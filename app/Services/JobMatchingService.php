<?php

namespace App\Services;

use App\Models\EmployerCultureProfile;
use App\Models\Job;
use App\Models\User;
use App\Services\Semantic\SemanticMatchingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Canonical GridSpace matching engine.
 *
 * Produces an explainable 0-100 compatibility score between a candidate and
 * a job using eight weighted components configured in config/matching.php.
 *
 * Professional domain compatibility acts as a hard gate: candidates and jobs
 * from unrelated domains can never exceed the domain_gate_cap. Components with
 * missing data on either side are excluded from the weighted calculation
 * (not rewarded with high neutral scores). Every component returns
 * human-readable reasons and gaps plus an "applicable" flag.
 *
 * Candidate-side behavioral intelligence (config/matching.php behavioral
 * block) layers a bounded ranking modifier on top of the pure profile score in
 * recommendJobsForCandidate. It can nudge an already-compatible match but can
 * never override the professional domain gate, and it is never consumed by
 * the employer-facing candidate ranking path.
 *
 * Phase 5 semantic understanding (config/matching.php semantic block) layers a
 * second, equally bounded ranking modifier that understands wording variance
 * (Backend Developer vs Backend Engineer, Postgres vs PostgreSQL). It is
 * opt-in, additive-only, capped by semantic.maximum_influence, never satisfies
 * a required skill, never crosses the domain gate and never runs for
 * incompatible pairs - so an excellent structured match always outranks a weak
 * one regardless of semantic similarity. Its evidence is surfaced in the
 * semantic_similarity/semantic_reasons fields of ranking items.
 */
class JobMatchingService
{
    public function __construct(
        protected CandidateBehavioralProfileService $behavior,
        protected CandidateFeedbackSignalService $feedbackSignals,
        protected SemanticMatchingService $semantic,
    ) {}

    public function overall(User $candidate, Job $job): int
    {
        return $this->calculateBreakdown($candidate, $job)['overall_score'];
    }

    public function getMatchBreakdown(User $candidate, Job $job): array
    {
        return $this->calculateBreakdown($candidate, $job);
    }

    /**
     * @return array{
     *     overall_score: int,
     *     profile_match_score: int,
     *     recommendation_score: int,
     *     match_status: string,
     *     category: string,
     *     domain_compatible: bool,
     *     candidate_domain: ?string,
     *     job_domain: ?string,
     *     components: array<string, array{score: int, label: string, reasons: array, gaps: array, applicable: bool}>,
     *     matched_skills: array,
     *     missing_skills: array,
     *     strengths: array,
     *     gaps: array,
     *     hard_gaps: array,
     *     soft_gaps: array,
     *     reasons: array
     * }
     */
    public function calculateBreakdown(User $candidate, Job $job): array
    {
        $components = [
            'skills' => $this->scoreSkills($candidate, $job),
            'role' => $this->scoreRole($candidate, $job),
            'experience' => $this->scoreExperience($candidate, $job),
            'personality' => $this->scorePersonality($candidate, $job),
            'work_preference' => $this->scoreWorkPreference($candidate, $job),
            'salary' => $this->scoreSalary($candidate, $job),
            'education' => $this->scoreEducation($candidate, $job),
            'availability' => $this->scoreAvailability($candidate, $job),
        ];

        $weighted = 0;
        $totalWeight = 0;

        foreach ($components as $key => $component) {
            $weight = $this->weight($key);

            if ($component['applicable'] && $weight > 0) {
                $weighted += $component['score'] * $weight;
                $totalWeight += $weight;
            }
        }

        $overall = $totalWeight > 0 ? (int) round($weighted / $totalWeight) : 0;

        $candidateDomains = $this->resolveCandidateDomains($candidate);
        $jobDomains = $this->resolveJobDomains($job);
        $domainsCompatible = $this->isDomainsCompatible($candidateDomains, $jobDomains);

        // The behavioural, semantic and search-history signals are never allowed
        // to paper over a genuinely incompatible professional pairing. When
        // both sides carry evidence the overall score is hard-capped below the
        // "potential" band so a wrong-field relationship cannot be recommended.
        $gateApplied = $candidateDomains !== [] && $jobDomains !== [] && ! $domainsCompatible;

        if ($gateApplied) {
            $overall = min($overall, (int) config('matching.domain_gate_cap', 15));
        }

        // Legacy single-domain fields for consumers that still read strings.
        $candidateDomain = $candidateDomains[0] ?? null;
        $jobDomain = $jobDomains[0] ?? null;
        $domainCompatible = $domainsCompatible;

        $overall = max(0, min(100, $overall));

        return [
            'overall_score' => $overall,
            'profile_match_score' => $overall,
            'recommendation_score' => $overall,
            'match_status' => $this->matchStatusFor($overall, $gateApplied),
            'category' => $this->categoryFor($overall),
            'domain_compatible' => $domainCompatible,
            'candidate_domain' => $candidateDomain
                ? config("professional_domains.domains.{$candidateDomain}.label", $candidateDomain)
                : null,
            'job_domain' => $jobDomain
                ? config("professional_domains.domains.{$jobDomain}.label", $jobDomain)
                : null,
            'components' => $components,
            'matched_skills' => $this->skillNames($components['skills']['details']['matched'] ?? []),
            'missing_skills' => $this->skillNames($components['skills']['details']['missing'] ?? []),
            'strengths' => $this->collectStrengths($components),
            'gaps' => $this->collectGaps($components),
            'hard_gaps' => $this->collectHardGaps($components),
            'soft_gaps' => $this->collectSoftGaps($components),
            'reasons' => $this->collectReasons($components),
        ];
    }

    /**
     * Determine the professional domain of a role string using the
     * config/professional_domains.php taxonomy. Returns null when the role is
     * empty or ambiguous (tied scores across domains).
     */
    public function getDomain(?string $role): ?string
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        $normalized = strtolower(trim($role));
        $domains = config('professional_domains.domains', []);

        $scores = [];

        foreach ($domains as $key => $domain) {
            $score = 0;

            foreach ($domain['keywords'] as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $score += str_word_count($keyword) >= 2 ? 3 : 1;
                }
            }

            if ($score > 0) {
                $scores[$key] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $topDomain = array_key_first($scores);
        $topScore = $scores[$topDomain];

        $secondScore = 0;

        foreach ($scores as $key => $score) {
            if ($key !== $topDomain) {
                $secondScore = $score;
                break;
            }
        }

        return $topScore > $secondScore ? $topDomain : null;
    }

    /**
     * Resolve the full set of professional domains for a candidate, preferring
     * official taxonomy links (desired category + role) and falling back to the
     * legacy keyword classifier. Returns an empty array when neither taxonomy
     * nor keyword evidence is present - an empty array never hard-gates.
     *
     * @return array<int, string>
     */
    public function resolveCandidateDomains(User $candidate): array
    {
        $domains = [];

        $profile = $candidate->candidateProfile;
        $category = $profile?->desiredCategory;
        $role = $profile?->desiredRole;

        foreach ((array) ($category?->domain_keys ?? []) as $key) {
            if (is_string($key) && $key !== '') {
                $domains[$key] = true;
            }
        }

        if ($role !== null) {
            $detected = $this->getDomain((string) $role->name);

            if ($detected !== null) {
                $domains[$detected] = true;
            }
        }

        $legacy = $this->getDomain((string) ($profile?->desired_role ?? ''));

        if ($legacy !== null) {
            $domains[$legacy] = true;
        }

        return array_keys($domains);
    }

    /**
     * Resolve the full set of professional domains for a job, preferring
     * official taxonomy links (category + role) over the keyword classifier.
     *
     * @return array<int, string>
     */
    public function resolveJobDomains(Job $job): array
    {
        $domains = [];

        foreach ((array) ($job->professionalCategory?->domain_keys ?? []) as $key) {
            if (is_string($key) && $key !== '') {
                $domains[$key] = true;
            }
        }

        if ($job->professionalRole !== null) {
            $detected = $this->getDomain((string) $job->professionalRole->name);

            if ($detected !== null) {
                $domains[$detected] = true;
            }
        }

        $legacy = $this->getDomain(trim((string) ($job->title ?? '').' '.(string) ($job->role ?? '')));

        if ($legacy !== null) {
            $domains[$legacy] = true;
        }

        return array_keys($domains);
    }

    /**
     * Array-based domain compatibility: shared domain, or an explicitly
     * related domain edge from config ('professional_domains.related').
     * Related edges are adjacency evidence, never equivalence - a related pair
     * is still scored on token overlap, skills and experience rather than being
     * treated as the same role. Two disjoint, unrelated arrays are hard
     * incompatible.
     *
     * @param  array<int, string>  $candidateDomains
     * @param  array<int, string>  $jobDomains
     */
    public function isDomainsCompatible(array $candidateDomains, array $jobDomains): bool
    {
        if ($candidateDomains === [] || $jobDomains === []) {
            return true;
        }

        $shared = array_intersect($candidateDomains, $jobDomains);

        if ($shared !== []) {
            return true;
        }

        $related = config('professional_domains.related', []);

        foreach ($candidateDomains as $candidate) {
            foreach ($jobDomains as $job) {
                if (isset($related[$candidate]) && in_array($job, $related[$candidate], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Two domains are compatible when matchable. An unknown domain on either
     * side never triggers the hard gate, since we cannot prove incompatibility.
     */
    private function isDomainCompatible(?string $candidateDomain, ?string $jobDomain): bool
    {
        if ($candidateDomain === null || $jobDomain === null) {
            return true;
        }

        return $candidateDomain === $jobDomain;
    }

    public function categoryFor(int $score): string
    {
        $thresholds = config('matching.thresholds');
        $labels = config('matching.labels');

        return match (true) {
            $score >= $thresholds['excellent'] => $labels['excellent'],
            $score >= $thresholds['strong'] => $labels['strong'],
            $score >= $thresholds['good'] => $labels['good'],
            $score >= $thresholds['potential'] => $labels['potential'],
            default => $labels['low'],
        };
    }

    /**
     * Engine-level match status. The professional domain gate has priority:
     * whenever it capped the score the status must be 'incompatible', even if
     * the numeric score would otherwise look middling.
     */
    public function matchStatusFor(int $profileScore, bool $gateApplied = false): string
    {
        if ($gateApplied) {
            return 'incompatible';
        }

        $statuses = config('matching.statuses');

        foreach (['excellent', 'strong', 'moderate', 'weak'] as $status) {
            $min = (int) ($statuses[$status]['min'] ?? 0);

            if ($profileScore >= $min) {
                return $status;
            }
        }

        return 'weak';
    }

    public function weight(string $component): int
    {
        return (int) config("matching.weights.{$component}", 0);
    }

    /**
     * Rank open jobs for a candidate with optional filters, paginated.
     *
     * Behavioral intelligence modulates the ranking of professionally
     * compatible jobs via a bounded boost (never overriding the domain gate).
     * overall_score / profile_match_score stay profile-only; recommendation_score
     * and final_score are the combined ranking values displayed to the
     * candidate. Deterministic hard-prune filters are applied before scoring so
     * implausible jobs are never fully scored, and identical listings are
     * collapsed while per-company diversity is preserved.
     *
     * Additional filters: min_score, exclude_applied.
     *
     * @return Collection<int, array{job: Job, overall_score: int, profile_match_score: int, recommendation_score: int, final_score: int, match_status: string, behavioral_relevance: int, behavioral_reasons: array, semantic_similarity: ?int, semantic_points: int, semantic_reasons: array, semantic_details: array, semantic_provider: ?string, semantic_fallback: bool, category: string, matched_skills: array, missing_skills: array, top_reasons: array, hard_gaps: array, soft_gaps: array}>
     */
    public function recommendJobsForCandidate(User $candidate, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $query = Job::query()
            ->where('status', 'open')
            ->where('employer_id', '!=', $candidate->id)
            ->with(['company', 'jobSkills.skill']);

        $query = $this->applyJobFilters($query, $filters);

        $jobs = $query->get();

        $prune = config('matching.recommendation.hard_prune', []);
        $appliedJobIds = [];

        if (! empty($filters['exclude_applied'])) {
            $appliedJobIds = $candidate->jobApplications()->pluck('job_id')->all();
        }

        $behaviorProfile = $this->behavior->isActive($candidate)
            ? $candidate->behavioralProfile
            : null;

        $ranked = $jobs
            ->reject(fn (Job $job) => ! $this->passesJobPrune($candidate, $job, $prune))
            ->reject(fn (Job $job) => in_array($job->id, $appliedJobIds, true))
            ->map(function (Job $job) use ($candidate, $behaviorProfile) {
                $breakdown = $this->calculateBreakdown($candidate, $job);

                [$relevance, $boost, $behaviorReasons] = $this->behavior->forRecommendation(
                    $behaviorProfile,
                    $job,
                    $breakdown
                );

                $semantic = $this->semantic->forPair($candidate, $job, $breakdown);

                $recommendationScore = min(100, $breakdown['profile_match_score'] + $boost + $semantic['points']);

                $recommendationScore = $this->applyNegativeSignals($candidate, $job, $recommendationScore);

                return [
                    'job' => $job,
                    'overall_score' => $breakdown['overall_score'],
                    'profile_match_score' => $breakdown['profile_match_score'],
                    'recommendation_score' => $recommendationScore,
                    'final_score' => $recommendationScore,
                    'match_status' => $breakdown['match_status'],
                    'behavioral_relevance' => $relevance,
                    'behavioral_reasons' => $behaviorReasons,
                    'semantic_similarity' => $semantic['score'],
                    'semantic_points' => $semantic['points'],
                    'semantic_reasons' => $semantic['reasons'],
                    'semantic_details' => $semantic['details'],
                    'semantic_provider' => $semantic['provider'],
                    'semantic_fallback' => $semantic['fallback'],
                    'category' => $breakdown['category'],
                    'matched_skills' => array_slice($breakdown['matched_skills'], 0, 5),
                    'missing_skills' => array_slice($breakdown['missing_skills'], 0, 3),
                    'top_reasons' => array_slice($breakdown['reasons'], 0, 3),
                    'hard_gaps' => $breakdown['hard_gaps'],
                    'soft_gaps' => $breakdown['soft_gaps'],
                    'breakdown' => $breakdown,
                ];
            })
            ->filter(fn (array $item) => ($filters['min_score'] ?? 0) <= $item['overall_score'])
            ->sortBy(fn (array $item) => [$item['final_score'], $item['overall_score']], SORT_REGULAR, true)
            ->when(config('matching.recommendation.dedupe_identical_jobs', true), fn ($collection) => $this->dedupeIdenticalJobs($collection))
            ->pipe(fn ($collection) => $this->diversify($collection))
            ->values();

        return $this->paginateCollection($ranked, $perPage);
    }

    /**
     * Rank candidates for a job with optional filters, paginated.
     *
     * Employer-facing: behavior never influences this ranking and no behavioral
     * data is exposed. Deterministic hard-prune filters run before scoring, and
     * applied candidates can be excluded with the exclude_applied filter.
     *
     * @return Collection<int, array{candidate: User, overall_score: int, profile_match_score: int, recommendation_score: int, match_status: string, category: string, semantic_similarity: ?int, semantic_points: int, semantic_reasons: array, semantic_details: array, semantic_provider: ?string, semantic_fallback: bool, matched_skills: array, missing_skills: array, strengths: array}>
     */
    public function rankCandidatesForJob(Job $job, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $query = User::query()
            ->where('role', 'candidate')
            ->where('onboarding_completed', true)
            ->with([
                'candidateProfile',
                'candidateSkills.skill',
                'personalityProfile',
                'candidateEducation',
            ]);

        $query = $this->applyCandidateFilters($query, $filters);

        $prune = config('matching.recommendation.hard_prune', []);
        $appliedCandidateIds = [];

        if (! empty($filters['exclude_applied'])) {
            $appliedCandidateIds = $job->applications()->pluck('candidate_id')->all();
        }

        $candidates = $query->get()
            ->reject(fn (User $candidate) => in_array($candidate->id, $appliedCandidateIds, true))
            ->filter(fn (User $candidate) => $this->passesCandidatePrune($candidate, $job, $prune))
            ->values();

        $ranked = $candidates
            ->map(function (User $candidate) use ($job) {
                $breakdown = $this->calculateBreakdown($candidate, $job);

                $semantic = $this->semantic->forPair($candidate, $job, $breakdown, 'employer');

                return [
                    'candidate' => $candidate,
                    'overall_score' => $breakdown['overall_score'],
                    'profile_match_score' => $breakdown['profile_match_score'],
                    'recommendation_score' => min(100, $breakdown['recommendation_score'] + $semantic['points']),
                    'match_status' => $breakdown['match_status'],
                    'category' => $breakdown['category'],
                    'semantic_similarity' => $semantic['score'],
                    'semantic_points' => $semantic['points'],
                    'semantic_reasons' => $semantic['reasons'],
                    'semantic_details' => $semantic['details'],
                    'semantic_provider' => $semantic['provider'],
                    'semantic_fallback' => $semantic['fallback'],
                    'matched_skills' => array_slice($breakdown['matched_skills'], 0, 5),
                    'missing_skills' => array_slice($breakdown['missing_skills'], 0, 4),
                    'strengths' => array_slice($breakdown['strengths'], 0, 3),
                    'breakdown' => $breakdown,
                ];
            })
            ->filter(fn (array $item) => ($filters['min_score'] ?? 0) <= $item['overall_score'])
            ->sortByDesc('overall_score')
            ->values();

        return $this->paginateCollection($ranked, $perPage);
    }

    /**
     * Candidate feedback negative signals (bounded, decaying) modulate the
     * recommendation ranking score only. Never touches profile_match_score,
     * match_status or the domain gate.
     */
    protected function applyNegativeSignals(User $candidate, Job $job, int $recommendationScore): int
    {
        if (! (bool) config('matching.recommendation.negative_signal.enabled', true)) {
            return $recommendationScore;
        }

        if ($this->feedbackSignals->totalPenalty($candidate, $job) <= 0) {
            return $recommendationScore;
        }

        return max(0, $recommendationScore - $this->feedbackSignals->totalPenalty($candidate, $job));
    }

    // ------------------------------------------------------------------
    // Component scorers
    // ------------------------------------------------------------------

    /**
     * @return array{score: int, label: string, reasons: array, gaps: array, details: array, applicable: bool}
     */
    public function scoreSkills(User $candidate, Job $job): array
    {
        $config = config('matching.skills');

        $candidateSkills = $candidate->candidateSkills()
            ->with('skill')
            ->get()
            ->keyBy(function ($skill) {
                return $skill->skill_id ?: $this->normalizeSkill($skill->skill_name);
            });

        $jobSkills = $job->jobSkills()
            ->with('skill')
            ->get()
            ->sortByDesc('is_required')
            ->values();

        $requiredSkillsJson = $job->getRequiredSkills();

        // No skill requirements at all on the job side: the skills component
        // is not applicable rather than rewarded with a neutral score.
        if ($jobSkills->isEmpty() && empty($requiredSkillsJson)) {
            return [
                'score' => 0,
                'label' => 'Skills',
                'reasons' => [],
                'gaps' => [],
                'details' => ['matched' => [], 'missing' => []],
                'applicable' => false,
            ];
        }

        // Fall back to the free-text required skills list when no normalized
        // job skills have been attached to the listing.
        if ($jobSkills->isEmpty()) {
            return $this->scoreSkillsFromNames(
                $candidate->candidateSkills()->get(),
                $requiredSkillsJson,
                $config
            );
        }

        if ($candidateSkills->isEmpty()) {
            return [
                'score' => 0,
                'label' => 'Skills',
                'reasons' => [],
                'gaps' => ['Your skill list is empty - add your skills to improve matching accuracy.'],
                'details' => ['matched' => [], 'missing' => $jobSkills->map(fn ($js) => $js->skill?->name ?? (string) $js->skill_id)->all()],
                'applicable' => true,
            ];
        }

        $matched = [];
        $missing = [];
        $proficiencyBonus = 0;

        foreach ($jobSkills as $jobSkill) {
            $isRequired = (bool) $jobSkill->is_required;
            $name = $jobSkill->skill?->name ?? (string) $jobSkill->skill_id;
            $lookupKey = $jobSkill->skill_id ?: $this->normalizeSkill($name);

            $candidateSkill = $candidateSkills->get($lookupKey);

            if (! $candidateSkill) {
                $missing[] = ['name' => $name, 'required' => $isRequired];

                continue;
            }

            $meetsProficiency = ($candidateSkill->proficiency_level ?? 0) >= ($jobSkill->min_proficiency ?? 1);

            if ($isRequired && ! $meetsProficiency) {
                $missing[] = ['name' => $name, 'required' => true];

                continue;
            }

            $matched[] = ['name' => $name, 'required' => $isRequired];

            if ($meetsProficiency && ($candidateSkill->proficiency_level ?? 0) > ($jobSkill->min_proficiency ?? 1)) {
                $proficiencyBonus += 2;
            }
        }

        $requiredTotal = $jobSkills->where('is_required', true)->count();
        $preferredTotal = $jobSkills->count() - $requiredTotal;

        $requiredMatched = collect($matched)->where('required', true)->count();
        $preferredMatched = collect($matched)->where('required', false)->count();

        $requiredScore = $requiredTotal > 0 ? ($requiredMatched / $requiredTotal) * 100 : 100;
        $preferredScore = $preferredTotal > 0 ? ($preferredMatched / $preferredTotal) * 100 : 100;

        $score = ($requiredScore * $config['required_weight']) + ($preferredScore * $config['preferred_weight']);

        $bonus = min(
            ($preferredMatched * $config['preferred_bonus_per_skill']),
            $config['preferred_bonus_cap']
        );

        if ($requiredTotal === 0 && $requiredMatched > 0) {
            $bonus += min($requiredMatched * $config['preferred_bonus_per_skill'], $config['preferred_bonus_cap']);
        }

        $score = (int) round(min(100, $score + min($bonus + $proficiencyBonus, $config['preferred_bonus_cap'] + $config['proficiency_bonus_cap'])));

        $reasons = [];
        $gaps = [];

        if ($requiredTotal > 0 && $requiredMatched === $requiredTotal) {
            $reasons[] = 'All required skills are present';
        } elseif ($requiredMatched > 0) {
            $reasons[] = "{$requiredMatched} of {$requiredTotal} required skills matched";
        }

        foreach (collect($missing)->where('required', true)->pluck('name') as $name) {
            $gaps[] = "Missing required skill: {$name}";
        }

        return [
            'score' => $score,
            'label' => 'Skills',
            'reasons' => $reasons,
            'gaps' => $gaps,
            'details' => ['matched' => $matched, 'missing' => $missing],
            'applicable' => true,
        ];
    }

    private function scoreSkillsFromNames(Collection $candidateSkills, array $requiredNames, array $config): array
    {
        $requiredNames = array_values(array_filter(array_map('trim', $requiredNames)));

        if ($requiredNames === []) {
            return [
                'score' => 0,
                'label' => 'Skills',
                'reasons' => [],
                'gaps' => [],
                'details' => ['matched' => [], 'missing' => []],
                'applicable' => false,
            ];
        }

        $owned = $candidateSkills
            ->pluck('skill_name')
            ->map(fn ($name) => $this->normalizeSkill((string) $name))
            ->filter()
            ->flip();

        $matched = [];
        $missing = [];

        foreach ($requiredNames as $name) {
            if ($owned->has($this->normalizeSkill($name))) {
                $matched[] = ['name' => $name, 'required' => true];
            } else {
                $missing[] = ['name' => $name, 'required' => true];
            }
        }

        $ratio = count($matched) / count($requiredNames);

        return [
            'score' => (int) round($ratio * 100),
            'label' => 'Skills',
            'reasons' => $ratio === 1.0 ? ['All required skills are present'] : [count($matched).' of '.count($requiredNames).' required skills matched'],
            'gaps' => collect($missing)->pluck('name')->map(fn ($n) => "Missing required skill: {$n}")->all(),
            'details' => ['matched' => $matched, 'missing' => $missing],
            'applicable' => true,
        ];
    }

    public function scoreRole(User $candidate, Job $job): array
    {
        $desired = trim((string) ($candidate->candidateProfile?->desired_role ?? ''));

        if ($desired === '') {
            return [
                'score' => $this->neutral(),
                'label' => 'Role Alignment',
                'reasons' => [],
                'gaps' => ['Add your desired role to see how well positions align with your goals.'],
                'applicable' => false,
            ];
        }

        $jobText = trim(($job->title ?? '').' '.($job->role ?? ''));

        if ($jobText === '') {
            return [
                'score' => $this->neutral(),
                'label' => 'Role Alignment',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        // Cross-domain roles are fundamentally incompatible: return a low
        // score with a domain-aware explanation instead of token guessing.
        $candidateDomain = $this->getDomain($desired);
        $jobDomain = $this->getDomain($jobText);

        if ($candidateDomain !== null && $jobDomain !== null && $candidateDomain !== $jobDomain) {
            $candidateLabel = config("professional_domains.domains.{$candidateDomain}.label", 'your field');
            $jobLabel = config("professional_domains.domains.{$jobDomain}.label", 'this field');

            return [
                'score' => 10,
                'label' => 'Role Alignment',
                'reasons' => ["{$candidateLabel} and {$jobLabel} are different professional fields"],
                'gaps' => ['The role is in a different professional domain than your desired role'],
                'applicable' => true,
            ];
        }

        $desiredTokens = $this->canonicalTokens($desired);
        $jobTokens = $this->canonicalTokens($jobText);

        $score = $this->tokenOverlapScore($desiredTokens, $jobTokens);

        $desiredNorm = implode(' ', $desiredTokens);
        $jobNorm = implode(' ', $jobTokens);

        if ($desiredNorm !== '' && str_contains($jobNorm, $desiredNorm)) {
            $score = max($score, 90);
        }

        if ($desiredNorm === $jobNorm) {
            $score = 100;
        }

        $profileIndustry = strtolower(trim((string) ($candidate->candidateProfile?->industry ?? '')));
        $jobIndustry = strtolower(trim((string) ($job->industry ?? '')));

        $reasons = [];

        if ($profileIndustry !== '' && $jobIndustry !== '' && $profileIndustry === $jobIndustry) {
            $score = min(100, $score + 5);
            $reasons[] = "Your {$candidate->candidateProfile->industry} background aligns with this role's industry";
        }

        if ($score >= 85) {
            $reasons[] = "Your desired role closely matches \"{$job->title}\"";
        } elseif ($score >= 60) {
            $reasons[] = "Your desired role partially overlaps with \"{$job->title}\"";
        } else {
            $reasons[] = "This role differs from your desired role ({$desired})";
        }

        return [
            'score' => $score,
            'label' => 'Role Alignment',
            'reasons' => $reasons,
            'gaps' => $score < 45 ? ["\"{$job->title}\" is quite different from your desired role"] : [],
            'applicable' => true,
        ];
    }

    public function scoreExperience(User $candidate, Job $job): array
    {
        $profile = $candidate->candidateProfile;

        if (! $profile) {
            return [
                'score' => $this->neutral(),
                'label' => 'Experience',
                'reasons' => [],
                'gaps' => [],
                'details' => ['requirements_met' => [], 'requirements_missing' => []],
                'applicable' => false,
            ];
        }

        $years = (int) ($profile->years_of_experience ?? 0);
        $required = max((int) ($job->minimum_experience ?? 0), $this->levelFloor($job->experience_level));

        [$met, $unmet] = $this->experienceRequirements($candidate, $job, $years);

        if ($required === 0 && $met === [] && $unmet === []) {
            return [
                'score' => $this->neutral(),
                'label' => 'Experience',
                'reasons' => [],
                'gaps' => [],
                'details' => ['requirements_met' => [], 'requirements_missing' => []],
                'applicable' => false,
            ];
        }

        $config = config('matching.experience');

        if ($years >= $required) {
            $excess = $years - $required;
            $score = 100;

            if ($excess > $config['sweet_spot_years']) {
                $score = max(85, 100 - (($excess - $config['sweet_spot_years']) * $config['overqualified_decay']));
            }

            return [
                'score' => $score,
                'label' => 'Experience',
                'reasons' => ["Your {$years} ".Str::plural('year', $years)." of experience meets the {$required}-year requirement"],
                'gaps' => [],
                'details' => ['requirements_met' => $met, 'requirements_missing' => $unmet],
                'applicable' => true,
            ];
        }

        $shortfall = $required - $years;
        $score = max(25, (int) round(($years / $required) * 100));

        return [
            'score' => $score,
            'label' => 'Experience',
            'reasons' => [$years.' of '.$required.' required '.Str::plural('year', $required).' of experience'],
            'gaps' => [$shortfall.' '.Str::plural('more year', $shortfall).' of experience typically expected for this role'],
            'details' => ['requirements_met' => $met, 'requirements_missing' => $unmet],
            'applicable' => true,
        ];
    }

    private function experienceRequirements(User $candidate, Job $job, int $years): array
    {
        $met = [];
        $unmet = [];

        foreach ($job->jobRequirements()->where('requirement_type', 'experience')->get() as $requirement) {
            $value = strtolower((string) $requirement->requirement_value);

            $satisfied = match ($value) {
                'junior' => $years <= 3,
                'mid' => $years >= 2 && $years <= 5,
                'senior' => $years >= 5,
                'lead' => $years >= 7,
                default => $years >= max(0, (int) $requirement->requirement_value),
            };

            if ($satisfied) {
                $met[] = $requirement->requirement_value;
            } else {
                $unmet[] = $requirement->requirement_value;
            }
        }

        return [$met, $unmet];
    }

    public function scorePersonality(User $candidate, Job $job): array
    {
        $profile = $candidate->personalityProfile;

        if (! $profile || ! $profile->assessment_completed) {
            return [
                'score' => $this->neutral(),
                'label' => 'Work Style Compatibility',
                'reasons' => [],
                'gaps' => ['Complete the personality assessment for work-style compatibility insights.'],
                'applicable' => false,
            ];
        }

        $hasEmployerCulture = EmployerCultureProfile::where('employer_id', $job->employer_id)->exists();
        $hasJobPreferences = $job->temperament_preference !== null
            || ! empty($job->personality_preferences_json)
            || $hasEmployerCulture;

        if (! $hasJobPreferences) {
            return [
                'score' => $this->neutral(),
                'label' => 'Work Style Compatibility',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $signals = [];

        $temperament = $this->temperamentSignal($candidate, $job);

        if ($temperament !== null) {
            $signals[] = ['score' => $temperament['score'], 'weight' => 40, 'reasons' => $temperament['reasons']];
        }

        $culture = $this->cultureSignal($candidate, $job);

        if ($culture !== null) {
            $signals[] = ['score' => $culture['score'], 'weight' => 40, 'reasons' => $culture['reasons'], 'gaps' => $culture['gaps']];
        }

        $ranges = $this->traitRangeSignal($candidate, $job);

        if ($ranges !== null) {
            $signals[] = ['score' => $ranges['score'], 'weight' => 20, 'reasons' => $ranges['reasons']];
        }

        if ($signals === []) {
            return [
                'score' => $this->neutral(),
                'label' => 'Work Style',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $totalWeight = array_sum(array_column($signals, 'weight'));
        $score = (int) round(array_sum(array_map(fn ($s) => $s['score'] * $s['weight'], $signals)) / max(1, $totalWeight));

        $reasons = array_merge(...array_map(fn ($s) => $s['reasons'], $signals));
        $gaps = array_merge(...array_map(fn ($s) => $s['gaps'] ?? [], $signals));

        return [
            'score' => $score,
            'label' => 'Work Style Compatibility',
            'reasons' => $reasons,
            'gaps' => $gaps,
            'applicable' => true,
        ];
    }

    /**
     * Standalone temperament compatibility in the job enum space
     * (analytical/driver/expressive/amiable), used by persisted scores.
     */
    public function temperamentScore(User $candidate, Job $job): int
    {
        $signal = $this->temperamentSignal($candidate, $job);

        return $signal['score'] ?? $this->neutral();
    }

    /**
     * Standalone employer-culture compatibility, used by persisted scores.
     */
    public function cultureScore(User $candidate, Job $job): int
    {
        $signal = $this->cultureSignal($candidate, $job);

        return $signal['score'] ?? $this->neutral();
    }

    private function temperamentSignal(User $candidate, Job $job): ?array
    {
        $candidateType = strtolower(trim((string) ($candidate->personalityProfile?->temperament_type ?? '')));
        $jobType = strtolower(trim((string) ($job->temperament_preference ?? '')));

        if ($candidateType === '' || $jobType === '') {
            return null;
        }

        $aliases = config('matching.temperament_aliases');
        $matrix = config('matching.temperament_matrix');

        $mapped = $aliases[$candidateType] ?? null;

        if ($mapped === null) {
            return null;
        }

        $score = $matrix[$mapped][$jobType] ?? 50;

        $readable = [
            'analytical' => 'analytical and methodical',
            'driver' => 'decisive and goal-driven',
            'expressive' => 'energetic and expressive',
            'amiable' => 'calm and steady',
        ];

        $jobReadable = $readable[$jobType] ?? $jobType;
        $mappedReadable = $readable[$mapped] ?? $mapped;

        $reasons = [];

        if ($score >= 90) {
            $reasons[] = 'Your '.$mappedReadable." temperament aligns with this role's preferred working style";
        } elseif ($score >= 65) {
            $reasons[] = 'Your '.$mappedReadable.' temperament works reasonably well with this role';
        }

        if ($score < 55) {
            $reasons[] = 'The role prefers a '.$jobReadable.' temperament which differs from yours - worth discussing during interviews';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    private function cultureSignal(User $candidate, Job $job): ?array
    {
        $culture = $job->employer?->employerCultureProfile;
        $profile = $candidate->personalityProfile;

        if (! $culture || ! $profile) {
            return null;
        }

        $scores = [];
        $reasons = [];
        $gaps = [];

        if ($culture->company_pace && $profile->work_style) {
            [$score, $note, $gap] = $this->paceCompatibility($profile->work_style, $culture->company_pace);

            if ($score !== null) {
                $scores[] = $score;

                if ($note !== '') {
                    $reasons[] = $note;
                }

                if ($gap !== '') {
                    $gaps[] = $gap;
                }
            }
        }

        if ($culture->work_environment && $profile->organizational_fit) {
            [$score, $note] = $this->environmentCompatibility($profile->organizational_fit, $culture->work_environment);

            if ($score !== null) {
                $scores[] = $score;

                if ($note !== '') {
                    $reasons[] = $note;
                }
            }
        }

        if ($culture->independence_level && $profile->collaboration_style) {
            [$score, $note] = $this->independenceCompatibility($profile->collaboration_style, $culture->independence_level);

            if ($score !== null) {
                $scores[] = $score;

                if ($note !== '') {
                    $reasons[] = $note;
                }
            }
        }

        if ($scores === []) {
            return null;
        }

        return [
            'score' => (int) round(array_sum($scores) / count($scores)),
            'reasons' => $reasons,
            'gaps' => $gaps,
        ];
    }

    private function traitRangeSignal(User $candidate, Job $job): ?array
    {
        $preferences = $job->personality_preferences_json ?? [];
        $dimensions = $candidate->personalityProfile?->dimension_scores ?? [];

        if ($preferences === [] || $dimensions === []) {
            return null;
        }

        $scores = [];

        foreach ($preferences as $trait => $range) {
            if (! is_array($range)) {
                continue;
            }

            $value = null;

            foreach ($dimensions as $dimension => $score) {
                if (strcasecmp((string) $dimension, (string) $trait) === 0) {
                    $value = (int) $score;
                    break;
                }
            }

            if ($value === null) {
                continue;
            }

            $min = $range['min'] ?? 0;
            $max = $range['max'] ?? 100;

            if ($value >= $min && $value <= $max) {
                $scores[] = 100;
            } elseif ($value < $min) {
                $scores[] = max(20, 100 - (($min - $value) * 2));
            } else {
                $scores[] = max(20, 100 - (($value - $max) * 2));
            }
        }

        if ($scores === []) {
            return null;
        }

        return [
            'score' => (int) round(array_sum($scores) / count($scores)),
            'reasons' => ['Matches the employer-stated work-style preferences for this position'],
        ];
    }

    private function paceCompatibility(string $workStyle, string $companyPace): array
    {
        foreach (config('matching.culture.pace_groups') as $group) {
            if (! in_array($workStyle, $group['work_styles'], true)) {
                continue;
            }

            foreach ($group['keywords'] as $keyword) {
                if (str_contains(strtolower($companyPace), $keyword)) {
                    return [100, '', ''];
                }
            }
        }

        $styleIsFast = in_array($workStyle, config('matching.culture.pace_groups.fast.work_styles'), true);
        $paceIsFast = false;

        foreach (config('matching.culture.pace_groups.fast.keywords') as $keyword) {
            if (str_contains(strtolower($companyPace), $keyword)) {
                $paceIsFast = true;
                break;
            }
        }

        if ($styleIsFast !== $paceIsFast) {
            return [45, '', 'Company operates at a '.strtolower($companyPace).' pace which may differ from your natural rhythm'];
        }

        return [null, '', ''];
    }

    private function environmentCompatibility(string $organizationalFit, string $workEnvironment): array
    {
        foreach (config('matching.culture.environment_groups') as $group) {
            if (! in_array($organizationalFit, $group['organizational_fits'], true)) {
                continue;
            }

            foreach ($group['keywords'] as $keyword) {
                if (str_contains(strtolower($workEnvironment), $keyword)) {
                    return [100, 'Company environment matches your preferred organizational setting', ''];
                }
            }
        }

        return [55, '', ''];
    }

    private function independenceCompatibility(string $collaborationStyle, string $independenceLevel): array
    {
        foreach (config('matching.culture.independence_groups') as $group) {
            if (! in_array($collaborationStyle, $group['collaboration_styles'], true)) {
                continue;
            }

            foreach ($group['keywords'] as $keyword) {
                if (str_contains(strtolower($independenceLevel), $keyword)) {
                    return [100, 'Working autonomy expectations align with your collaboration style', ''];
                }
            }
        }

        return [55, '', ''];
    }

    public function scoreWorkPreference(User $candidate, Job $job): array
    {
        $candidatePref = strtolower(trim((string) ($candidate->candidateProfile?->work_preference ?? '')));
        $jobPref = strtolower(trim((string) ($job->work_preference ?? '')));

        if ($candidatePref === '' || $jobPref === '') {
            return [
                'score' => $this->neutral(),
                'label' => 'Work Environment',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $matrix = config('matching.work_preference_matrix');

        $score = $matrix[$candidatePref][$jobPref] ?? 50;

        $reasons = [];
        $gaps = [];

        if ($score >= 85) {
            $reasons[] = ucfirst($jobPref).' working arrangement matches your preference';
        } elseif ($score >= 60) {
            $reasons[] = "This {$jobPref} role partially fits your {$candidatePref} preference";
        } else {
            $gaps[] = "Role is {$jobPref} while you prefer {$candidatePref} arrangements";
        }

        if (in_array($jobPref, ['onsite', 'hybrid'], true)) {
            $candidateCountry = strtolower(trim((string) ($candidate->candidateProfile->location_country ?? '')));
            $jobCountry = strtolower(trim((string) ($job->location_country ?? '')));

            if ($candidateCountry !== '' && $jobCountry !== '' && $candidateCountry !== $jobCountry && $score >= 60) {
                $score = min($score, 50);
                $gaps[] = 'Role is based in '.ucfirst($jobCountry).' which differs from your location';
            }
        }

        return [
            'score' => $score,
            'label' => 'Work Environment',
            'reasons' => $reasons,
            'gaps' => $gaps,
            'applicable' => true,
        ];
    }

    public function scoreSalary(User $candidate, Job $job): array
    {
        $expected = $candidate->candidateProfile?->salary_expectation;
        $min = $job->salary_min !== null ? (float) $job->salary_min : null;
        $max = $job->salary_max !== null ? (float) $job->salary_max : null;

        if (! $expected || ($min === null && $max === null)) {
            return [
                'score' => $this->neutral(),
                'label' => 'Salary',
                'reasons' => [],
                'gaps' => $expected ? [] : ['Add your salary expectation to refine salary compatibility.'],
                'applicable' => false,
            ];
        }

        $jobCurrency = strtoupper($job->salary_currency ?? 'NGN');
        $baseCurrency = strtoupper(config('matching.base_salary_currency', 'NGN'));

        // Candidate expectations carry no currency, so comparison is only
        // meaningful against jobs in the platform's base market currency.
        if ($jobCurrency !== $baseCurrency) {
            return [
                'score' => $this->neutral(),
                'label' => 'Salary',
                'reasons' => ["Salary comparison unavailable - this role is listed in {$jobCurrency}"],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $expected = (float) $expected;

        if (($min !== null && $expected >= $min) && ($max === null || $expected <= $max)) {
            return [
                'score' => 100,
                'label' => 'Salary',
                'reasons' => ['Advertised salary range covers your expectation'],
                'gaps' => [],
                'details' => ['within_range' => true],
                'applicable' => true,
            ];
        }

        if ($max !== null && $expected > $max) {
            $overshoot = ($expected - $max) / max($max, 1);
            $score = (int) round(max(25, 70 - ($overshoot * 100)));

            return [
                'score' => $score,
                'label' => 'Salary',
                'reasons' => [],
                'gaps' => ['Top of the advertised range is below your expectation'],
                'details' => ['within_range' => false],
                'applicable' => true,
            ];
        }

        // Expected less than the offered minimum: favourable to candidate.
        return [
            'score' => 95,
            'label' => 'Salary',
            'reasons' => ['Advertised salary exceeds your expectation'],
            'gaps' => [],
            'details' => ['within_range' => false],
            'applicable' => true,
        ];
    }

    public function scoreEducation(User $candidate, Job $job): array
    {
        $requirements = $job->jobRequirements()
            ->where('requirement_type', 'education')
            ->pluck('requirement_value')
            ->all();

        if ($requirements === []) {
            return [
                'score' => $this->neutral(),
                'label' => 'Education',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $education = $candidate->candidateEducation;

        if ($education->isEmpty()) {
            return [
                'score' => 20,
                'label' => 'Education',
                'reasons' => [],
                'gaps' => ['Add your education history so qualification requirements can be evaluated'],
                'applicable' => true,
            ];
        }

        $qualifications = $education->pluck('qualification')->filter()->all();
        $highestRank = 0;

        foreach ($qualifications as $qualification) {
            $highestRank = max($highestRank, $this->qualificationRank((string) $qualification));
        }

        foreach ($requirements as $requirement) {
            $normalized = strtolower($requirement);

            foreach ($qualifications as $qualification) {
                if (str_contains($this->normalizeQualification((string) $qualification), $this->normalizeQualification($requirement))) {
                    return [
                        'score' => 100,
                        'label' => 'Education',
                        'reasons' => ["Your qualifications include the requested credential ({$requirement})"],
                        'gaps' => [],
                        'applicable' => true,
                    ];
                }
            }

            $requiredRank = $this->qualificationRank($requirement);

            if ($requiredRank > 0 && $highestRank >= $requiredRank) {
                return [
                    'score' => 85,
                    'label' => 'Education',
                    'reasons' => ['Your highest qualification satisfies the educational level requested'],
                    'gaps' => [],
                    'applicable' => true,
                ];
            }
        }

        return [
            'score' => 40,
            'label' => 'Education',
            'reasons' => [],
            'gaps' => ['Role requests: '.implode(', ', $requirements)],
            'applicable' => true,
        ];
    }

    public function scoreAvailability(User $candidate, Job $job): array
    {
        $order = [
            'immediately' => 1,
            '2_weeks' => 2,
            '1_month' => 3,
            '2_months' => 4,
            '3_months' => 5,
            'passive' => 6,
        ];

        $jobRequirements = $job->jobRequirements()
            ->where('requirement_type', 'availability')
            ->pluck('requirement_value');

        if ($jobRequirements->isEmpty()) {
            return [
                'score' => $this->neutral(),
                'label' => 'Availability',
                'reasons' => [],
                'gaps' => [],
                'applicable' => false,
            ];
        }

        $availability = $candidate->candidateProfile?->availability;

        if (! $availability) {
            return [
                'score' => 20,
                'label' => 'Availability',
                'reasons' => [],
                'gaps' => ['Add your availability so start-date requirements can be evaluated'],
                'applicable' => true,
            ];
        }

        $candidateOrder = $order[$availability] ?? 6;

        foreach ($jobRequirements as $requirement) {
            if ($candidateOrder <= ($order[$requirement] ?? 6)) {
                return [
                    'score' => 100,
                    'label' => 'Availability',
                    'reasons' => ['You are available within the timeframe this employer needs'],
                    'gaps' => [],
                    'applicable' => true,
                ];
            }
        }

        return [
            'score' => 40,
            'label' => 'Availability',
            'reasons' => [],
            'gaps' => ['Employer needs someone available sooner than your stated availability'],
            'applicable' => true,
        ];
    }

    // ------------------------------------------------------------------
    // Filtering helpers
    // ------------------------------------------------------------------

    private function applyJobFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('role', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (! empty($filters['location'])) {
            $term = '%'.$filters['location'].'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('location', 'like', $term)
                    ->orWhere('location_country', 'like', $term);
            });
        }

        if (! empty($filters['work_preference'])) {
            $query->where('work_preference', $filters['work_preference']);
        }

        if (! empty($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }

        if (! empty($filters['role'])) {
            $term = '%'.$filters['role'].'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('role', 'like', $term)
                    ->orWhere('title', 'like', $term);
            });
        }

        if (! empty($filters['company'])) {
            $term = '%'.$filters['company'].'%';

            $query->whereHas('company', fn (Builder $q) => $q->where('name', 'like', $term));
        }

        if (! empty($filters['salary_min'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->where('salary_max', '>=', $filters['salary_min'])
                    ->orWhere('salary_min', '>=', $filters['salary_min']);
            });
        }

        if (! empty($filters['experience_max'])) {
            $query->where('minimum_experience', '<=', (int) $filters['experience_max']);
        }

        return $query;
    }

    private function applyCandidateFilters(Builder $query, array $filters): Builder
    {
        $query->whereHas('candidateProfile');

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';

            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhereHas('candidateProfile', fn (Builder $p) => $p
                        ->where('desired_role', 'like', $term)
                        ->orWhere('current_role', 'like', $term))
                    ->orWhereHas('candidateSkills', fn (Builder $p) => $p->where('skill_name', 'like', $term));
            });
        }

        if (! empty($filters['skills'])) {
            $terms = array_filter(array_map('trim', explode(',', (string) $filters['skills'])));

            foreach ($terms as $term) {
                $query->whereHas('candidateSkills', fn (Builder $p) => $p
                    ->where('skill_name', 'like', '%'.$term.'%')
                    ->orWhereHas('skill', fn (Builder $s) => $s->where('name', 'like', '%'.$term.'%')));
            }
        }

        if (isset($filters['experience_min']) && $filters['experience_min'] !== '') {
            $query->whereHas('candidateProfile', fn (Builder $p) => $p
                ->where('years_of_experience', '>=', (int) $filters['experience_min']));
        }

        if (isset($filters['experience_max']) && $filters['experience_max'] !== '') {
            $query->whereHas('candidateProfile', fn (Builder $p) => $p
                ->where('years_of_experience', '<=', (int) $filters['experience_max']));
        }

        if (! empty($filters['work_preference'])) {
            $query->whereHas('candidateProfile', fn (Builder $p) => $p
                ->where('work_preference', $filters['work_preference']));
        }

        if (! empty($filters['location'])) {
            $term = '%'.$filters['location'].'%';

            $query->whereHas('candidateProfile', fn (Builder $p) => $p
                ->where('location_country', 'like', $term));
        }

        if (! empty($filters['availability'])) {
            $query->whereHas('candidateProfile', fn (Builder $p) => $p
                ->where('availability', $filters['availability']));
        }

        return $query;
    }

    // ------------------------------------------------------------------
    // Hard pruning, deduplication and diversity
    // ------------------------------------------------------------------

    /**
     * Cheap, deterministic hard filters evaluated BEFORE scoring. A false
     * result drops the job outright; soft penalties belong in the scorers.
     */
    private function passesJobPrune(User $candidate, Job $job, array $prune): bool
    {
        $profile = $candidate->candidateProfile;

        if (! empty($prune['experience']) && $job->minimum_experience !== null) {
            $years = (int) ($profile?->years_of_experience ?? 0);

            if ($years < (int) $job->minimum_experience) {
                return false;
            }
        }

        if (! empty($prune['work_preference'])) {
            $candidatePref = strtolower((string) ($profile?->work_preference ?? ''));
            $jobPref = strtolower((string) ($job->work_preference ?? ''));

            if ($candidatePref !== '' && $jobPref !== '') {
                $score = config("matching.work_preference_matrix.{$candidatePref}.{$jobPref}", 50);

                if ($score <= 30) {
                    return false;
                }
            }
        }

        if (! empty($prune['location_country']) && in_array(strtolower((string) ($job->work_preference ?? '')), ['onsite', 'hybrid'], true)) {
            $candidateCountry = strtolower((string) ($profile?->location_country ?? ''));
            $jobCountry = strtolower((string) ($job->location_country ?? ''));

            if ($candidateCountry !== '' && $jobCountry !== '' && $candidateCountry !== $jobCountry) {
                return false;
            }
        }

        if (! empty($prune['required_skill_overlap'])) {
            $required = $this->normalizedSkillNames($job->getRequiredSkills());

            if ($required !== []) {
                $owned = $candidate->candidateSkills()->get()
                    ->map(function ($skill) {
                        return $this->normalizeSkill((string) ($skill->skill_name ?: $skill->skill?->name));
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($owned !== [] && array_intersect($owned, $required) === []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Candidate-side version of the hard prune used by employer-facing
     * ranking. Mirrors passesJobPrune exactly so both directions agree.
     */
    private function passesCandidatePrune(User $candidate, Job $job, array $prune): bool
    {
        $profile = $candidate->candidateProfile;

        if (! $profile) {
            return false;
        }

        if (! empty($prune['experience']) && $job->minimum_experience !== null) {
            $years = (int) ($profile->years_of_experience ?? 0);

            if ($years < (int) $job->minimum_experience) {
                return false;
            }
        }

        if (! empty($prune['work_preference'])) {
            $candidatePref = strtolower((string) ($profile->work_preference ?? ''));
            $jobPref = strtolower((string) ($job->work_preference ?? ''));

            if ($candidatePref !== '' && $jobPref !== '') {
                $score = config("matching.work_preference_matrix.{$candidatePref}.{$jobPref}", 50);

                if ($score <= 30) {
                    return false;
                }
            }
        }

        if (! empty($prune['location_country']) && in_array(strtolower((string) ($job->work_preference ?? '')), ['onsite', 'hybrid'], true)) {
            $candidateCountry = strtolower((string) ($profile->location_country ?? ''));
            $jobCountry = strtolower((string) ($job->location_country ?? ''));

            if ($candidateCountry !== '' && $jobCountry !== '' && $candidateCountry !== $jobCountry) {
                return false;
            }
        }

        return true;
    }

    private function dedupeIdenticalJobs(Collection $items): Collection
    {
        $seen = [];

        return $items
            ->filter(function (array $item) use (&$seen) {
                $job = $item['job'];
                $companyId = $job->company_id ?? $job->employer_id;

                if ($companyId === null) {
                    return true;
                }

                $key = (string) $companyId.'|'.$this->normalizeTitleKey($job->title);

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values();
    }

    private function diversify(Collection $items): Collection
    {
        $cap = (int) config('matching.recommendation.diversity_max_per_company', 3);

        if ($cap <= 0) {
            return $items->values();
        }

        $counts = [];
        $head = collect();
        $tail = collect();

        foreach ($items as $item) {
            $companyId = $item['job']->company_id ?? $item['job']->employer_id;

            if ($companyId === null) {
                $head->push($item);

                continue;
            }

            $current = $counts[$companyId] ?? 0;

            if ($current < $cap) {
                $head->push($item);
                $counts[$companyId] = $current + 1;
            } else {
                $tail->push($item);
            }
        }

        return $head->concat($tail)->values();
    }

    private function normalizeTitleKey(?string $title): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim((string) $title)));
    }

    private function normalizedSkillNames(array $names): array
    {
        return collect($names)
            ->map(fn ($name) => $this->normalizeSkill((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function neutral(): int
    {
        return (int) config('matching.neutral_score', 75);
    }

    private function levelFloor(?string $level): int
    {
        return match ($level) {
            'entry', 'junior' => 0,
            'mid' => 2,
            'senior' => 5,
            'lead' => 7,
            'principal' => 9,
            default => 0,
        };
    }

    private function qualificationRank(string $value): int
    {
        $value = strtolower($value);

        return match (true) {
            str_contains($value, 'phd') || str_contains($value, 'doctorate') => 6,
            str_contains($value, 'master') || str_contains($value, 'msc') || str_contains($value, 'mba') || str_contains($value, 'ma ') => 5,
            str_contains($value, 'bachelor') || str_contains($value, 'bsc') || str_contains($value, 'ba ') || str_contains($value, 'b.sc') => 4,
            str_contains($value, 'hnd') || str_contains($value, 'higher national') => 3,
            str_contains($value, 'national diploma') || str_contains($value, ' nd') || str_contains($value, 'diploma') => 2,
            str_contains($value, 'ssce') || str_contains($value, 'waec') || str_contains($value, 'secondary') => 1,
            default => 0,
        };
    }

    private function normalizeQualification(string $value): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9 ]/', '', strtolower($value))));
    }

    private function normalizeSkill(string $name): string
    {
        return trim(mb_strtolower(preg_replace('/[^a-z0-9+#. ]/i', '', trim($name))));
    }

    private function canonicalTokens(string $text): array
    {
        $stopWords = ['a', 'an', 'the', 'of', 'and', 'in', 'for', 'senior', 'junior', 'lead', 'principal'];

        $tokens = collect(preg_split('/[^a-z0-9+#]+/i', mb_strtolower($text)))
            ->map(fn ($token) => trim($token))
            ->filter()
            ->reject(fn ($token) => in_array($token, $stopWords, true))
            ->values();

        foreach (config('matching.role.synonym_groups', []) as $group) {
            $present = $tokens->intersect($group);

            if ($present->isNotEmpty()) {
                $tokens = $tokens->map(fn ($token) => $present->contains($token) ? $group[0] : $token)->unique()->values();
            }
        }

        return $tokens->all();
    }

    private function tokenOverlapScore(array $a, array $b): int
    {
        if ($a === [] || $b === []) {
            return 50;
        }

        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));

        return (int) round((count($intersection) / count($union)) * 100);
    }

    private function skillNames(array $items): array
    {
        return collect($items)->pluck('name')->filter()->unique()->values()->all();
    }

    private function collectStrengths(array $components): array
    {
        $strengths = [];

        $phrases = [
            'skills' => 'Strong coverage of the required skills',
            'role' => 'Desired role closely aligns with this position',
            'experience' => 'Experience level meets the role requirements',
            'personality' => 'Strong work-style compatibility with this environment',
            'work_preference' => 'Preferred work environment matches this role',
            'salary' => 'Salary expectations are compatible with the advertised range',
            'education' => 'Educational qualifications satisfy the requirements',
            'availability' => 'Availability aligns with the hiring timeline',
        ];

        foreach ($components as $key => $component) {
            if (($component['applicable'] ?? true) && $component['score'] >= 85 && isset($phrases[$key])) {
                $strengths[] = $phrases[$key];
            }
        }

        return $strengths;
    }

    private function collectGaps(array $components): array
    {
        $gaps = [];

        foreach ($components as $component) {
            if (($component['applicable'] ?? true)) {
                $gaps = array_merge($gaps, $component['gaps']);
            }
        }

        return array_values(array_unique($gaps));
    }

    /**
     * Deal-breaker gaps (missing required skills, unmet experience or
     * qualification requirements, incompatible arrangements or timing).
     */
    private function collectHardGaps(array $components): array
    {
        return $this->collectGapsFrom($components, config('matching.hard_gap_components', []));
    }

    /**
     * Negotiable gaps (salary stretch, culture fit, over-qualification).
     */
    private function collectSoftGaps(array $components): array
    {
        $hard = config('matching.hard_gap_components', []);
        $soft = array_diff(array_keys($components), array_values($hard));

        return $this->collectGapsFrom($components, array_values($soft));
    }

    private function collectGapsFrom(array $components, array $keys): array
    {
        $gaps = [];

        foreach ($keys as $key) {
            $component = $components[$key] ?? null;

            if (! $component || ! ($component['applicable'] ?? true)) {
                continue;
            }

            $gaps = array_merge($gaps, $component['gaps'] ?? []);
        }

        return array_values(array_unique($gaps));
    }

    private function collectReasons(array $components): array
    {
        $reasons = [];

        foreach ($components as $component) {
            if (($component['applicable'] ?? true)) {
                $reasons = array_merge($reasons, $component['reasons']);
            }
        }

        return array_values(array_slice(array_unique($reasons), 0, 6));
    }

    private function paginateCollection(Collection $items, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) request()->input('page', 1));

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
