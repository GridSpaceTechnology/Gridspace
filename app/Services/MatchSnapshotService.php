<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobMatchScore;
use App\Models\MatchSnapshot;
use App\Models\User;

/**
 * Immutable-by-version match score snapshots for quality measurement.
 *
 * A snapshot records the component-level match scores for a candidate/job
 * pair at a moment in time, tagged with the algorithm version and data
 * checksum that produced it. One snapshot is kept per (candidate, job,
 * source, algorithm version) so the analytics never re-score history but can
 * still compare bands across algorithm versions.
 *
 * Capture is deliberately cheap on hot paths: live breakdowns are reused when
 * provided, otherwise a fresh persisted JobMatchScore row is mapped, and only
 * a full recompute happens for rare, high-signal sources (applications,
 * shortlists, interviews).
 */
class MatchSnapshotService
{
    public const SOURCE_RECOMMENDED = 'recommended';

    public const SOURCE_VIEWED = 'viewed';

    public const SOURCE_APPLIED = 'applied';

    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        protected JobMatchingService $engine,
        protected MatchingEngineService $persistence,
        protected MatchChecksumService $checksums,
    ) {}

    /**
     * Capture (or refresh) the snapshot for a pair/source, reusing a provided
     * live breakdown when one is available to keep the hot paths recompute-free.
     */
    public function capture(User $candidate, Job $job, string $source, array $breakdown = [], ?int $recommendationScore = null): ?MatchSnapshot
    {
        if ($breakdown === []) {
            $breakdown = $this->engine->calculateBreakdown($candidate, $job);
        }

        $components = $breakdown['components'] ?? [];

        return MatchSnapshot::updateOrCreate(
            [
                'candidate_id' => $candidate->id,
                'job_id' => $job->id,
                'source' => $source,
                'algorithm_version' => (int) config('matching.algorithm_version', 0),
            ],
            [
                'profile_match_score' => ($breakdown['overall_score'] ?? $breakdown['profile_match_score'] ?? 0),
                'recommendation_score' => $recommendationScore ?? ($breakdown['recommendation_score'] ?? $breakdown['overall_score'] ?? 0),
                'match_status' => $breakdown['match_status'] ?? null,
                'skills_score' => $components['skills']['score'] ?? 0,
                'role_score' => $components['role']['score'] ?? 0,
                'experience_score' => $components['experience']['score'] ?? 0,
                'personality_score' => $components['personality']['score'] ?? 0,
                'work_preference_score' => $components['work_preference']['score'] ?? 0,
                'salary_score' => $components['salary']['score'] ?? 0,
                'education_score' => $components['education']['score'] ?? 0,
                'availability_score' => $components['availability']['score'] ?? 0,
                'matched_skills' => array_slice($breakdown['matched_skills'] ?? [], 0, 10),
                'missing_skills' => array_slice($breakdown['missing_skills'] ?? [], 0, 10),
                'data_checksum' => $this->checksums->for($candidate, $job),
                'scored_at' => now(),
            ]
        );
    }

    /**
     * Capture a snapshot of a recommendation item produced by
     * recommendJobsForCandidate(), which carries a full live breakdown.
     */
    public function captureRecommendation(User $candidate, Job $job, array $item): ?MatchSnapshot
    {
        return $this->capture(
            $candidate,
            $job,
            self::SOURCE_RECOMMENDED,
            $item['breakdown'] ?? [],
            $item['recommendation_score'] ?? $item['final_score'] ?? null,
        );
    }

    /**
     * Cheap capture for hot paths: reuse a fresh persisted JobMatchScore row
     * when one exists, otherwise fall back to a live breakdown when the
     * source is rare and high-signal (allowRecompute) or skip the snapshot
     * entirely on hot exposure paths.
     */
    public function captureFromPersisted(User $candidate, Job $job, string $source, bool $allowRecompute = true): ?MatchSnapshot
    {
        $row = $this->persistence->freshMatch($candidate, $job);

        if ($row !== null) {
            return MatchSnapshot::updateOrCreate(
                [
                    'candidate_id' => $candidate->id,
                    'job_id' => $job->id,
                    'source' => $source,
                    'algorithm_version' => (int) config('matching.algorithm_version', 0),
                ],
                [
                    'profile_match_score' => (int) $row->overall_match_score,
                    'recommendation_score' => (int) $row->recommendation_score,
                    'match_status' => (string) $row->match_status,
                    'skills_score' => (int) $row->skill_score,
                    'role_score' => (int) $row->role_score,
                    'experience_score' => (int) $row->experience_score,
                    'personality_score' => (int) $row->personality_score,
                    'work_preference_score' => (int) $row->work_preference_score,
                    'salary_score' => (int) $row->salary_score,
                    'education_score' => (int) $row->education_score,
                    'availability_score' => (int) $row->availability_score,
                    'matched_skills' => array_slice($row->matched_skills ?? [], 0, 10),
                    'missing_skills' => array_slice($row->missing_skills ?? [], 0, 10),
                    'data_checksum' => $row->data_checksum,
                    'scored_at' => now(),
                ]
            );
        }

        return $allowRecompute ? $this->capture($candidate, $job, $source) : null;
    }
}
