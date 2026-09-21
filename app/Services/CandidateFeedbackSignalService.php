<?php

namespace App\Services;

use App\Models\CandidateRecommendationFeedback;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Bounded, decaying negative signals for candidate-side ranking.
 *
 * Derived from explicit candidate feedback ("not relevant", "not interested",
 * "wrong role" etc.). Signals decay with a half-life and spill onto other jobs
 * in the same professional domain. A penalty is NEVER applied to the
 * professional profile match, the domain gate, or employer-facing rankings;
 * it only nudges the candidate-facing recommendation score inside a hard cap.
 */
class CandidateFeedbackSignalService
{
    protected ?Collection $rows = null;

    public function __construct(protected SearchIntentParser $parser) {}

    public function hasNegativeSignals(User $candidate): bool
    {
        return $this->rows($candidate)->isNotEmpty();
    }

    public function jobPenalty(User $candidate, int $jobId): float
    {
        return $this->rows($candidate)
            ->where('job_id', $jobId)
            ->sum(fn (CandidateRecommendationFeedback $feedback) => $this->decayedPoints($feedback));
    }

    public function domainPenalty(User $candidate, ?string $jobDomain, ?int $excludeJobId = null): float
    {
        if ($jobDomain === null) {
            return 0;
        }

        $decay = (float) config('matching.recommendation.negative_signal.domain_decay', 0.5);

        return $this->rows($candidate)
            ->filter(function (CandidateRecommendationFeedback $feedback) use ($jobDomain, $excludeJobId) {
                // The dismissed job itself is already penalized in full by
                // jobPenalty(); the spill only reaches other jobs in the domain.
                if ($excludeJobId !== null && (int) $feedback->job_id === $excludeJobId) {
                    return false;
                }

                $job = $feedback->job;

                if (! $job) {
                    return false;
                }

                return $this->parser->detectDomain(trim((string) $job->title).' '.(string) $job->role) === $jobDomain;
            })
            ->sum(fn (CandidateRecommendationFeedback $feedback) => $this->decayedPoints($feedback) * $decay);
    }

    public function totalPenalty(User $candidate, Job $job): int
    {
        if (! $this->hasNegativeSignals($candidate)) {
            return 0;
        }

        $domain = $this->parser->detectDomain(trim((string) $job->title).' '.(string) $job->role);
        $points = $this->jobPenalty($candidate, (int) $job->id)
            + $this->domainPenalty($candidate, $domain, (int) $job->id);

        return (int) min((float) config('matching.recommendation.negative_signal.penalty_cap', 30), $points);
    }

    /**
     * All negative feedback rows for a candidate, loaded at most once per
     * request to avoid N+1 during ranking.
     */
    protected function rows(User $candidate): Collection
    {
        if ($this->rows === null) {
            $this->rows = CandidateRecommendationFeedback::query()
                ->where('candidate_id', $candidate->id)
                ->where('is_relevant', false)
                ->with('job')
                ->get()
                ->filter(fn (CandidateRecommendationFeedback $feedback) => $feedback->isNegativeSignal())
                ->values();
        }

        return $this->rows;
    }

    protected function decayedPoints(CandidateRecommendationFeedback $feedback): float
    {
        $points = (float) config('matching.recommendation.negative_signal.penalty_points', 12);
        $halfLife = (float) config('matching.recommendation.negative_signal.half_life_days', 14);

        if ($halfLife <= 0) {
            return $points;
        }

        $elapsedDays = max(0, now()->getTimestamp() - $feedback->created_at->getTimestamp()) / 86400;

        return $points * pow(0.5, $elapsedDays / $halfLife);
    }
}
