<?php

namespace App\Services;

use App\Enums\MatchOutcomeEventType;
use App\Models\Interview;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\MatchOutcomeEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Appends match outcome events for internal quality measurement.
 *
 * Recording is deliberately thin: a single insert-or-ignore keyed on a
 * deterministic occurrence_key, so repeated or retried calls never double
 * count. Snapshots are attached only when cheaply available (persisted rows,
 * live recommendation items, or high-signal apply/shortlist moments).
 *
 * This log is the raw input for MatchAnalyticsService. It never mutates match
 * scores itself; candidate dismiss feedback is turned into a bounded, decaying
 * negative signal by the ranking engine, and nothing here bypasses the domain
 * gate or other hard compatibility rules.
 */
class MatchOutcomeService
{
    public function __construct(protected MatchSnapshotService $snapshots) {}

    /**
     * Append a single outcome event, idempotently. Returns null when the
     * occurrence already exists or the event type is unknown.
     *
     * @param  array{job?: Job, employer?: User, application?: JobApplication, interview?: Interview, candidate?: User, context?: array, occurred_at?: ?\DateTimeInterface}  $payload
     */
    public function record(string $eventType, array $payload = []): ?MatchOutcomeEvent
    {
        if (! MatchOutcomeEventType::valid($eventType)) {
            return null;
        }

        $candidate = $payload['candidate'] ?? null;
        $job = $payload['job'] ?? null;
        $employer = $payload['employer'] ?? null;
        $application = $payload['application'] ?? null;
        $interview = $payload['interview'] ?? null;

        $occurrenceKey = $this->occurrenceKey(
            $eventType,
            $candidate ? (int) $candidate->id : null,
            $job ? (int) $job->id : null,
            $employer ? (int) $employer->id : null,
            $application?->getKey(),
            $interview?->getKey(),
            $payload['context'] ?? []
        );

        $occurredAt = $payload['occurred_at'] ?? now();

        // Insert-or-ignore: the unique occurrence_key is the dedup boundary.
        // A retried or repeated call for an already-recorded occurrence simply
        // returns the existing row instead of raising a duplicate-key error.
        MatchOutcomeEvent::insertOrIgnore([
            [
                'id' => (string) Str::uuid(),
                'candidate_id' => $candidate?->id,
                'job_id' => $job?->id,
                'employer_id' => $employer?->id,
                'application_id' => $application?->id,
                'interview_id' => $interview?->id,
                'snapshot_id' => $payload['context']['snapshot'] ?? null,
                'event_type' => $eventType,
                'event_category' => MatchOutcomeEventType::from($eventType)->category(),
                'algorithm_version' => (int) config('matching.algorithm_version', 0),
                'occurrence_key' => $occurrenceKey,
                'context' => isset($payload['context']) ? json_encode($payload['context']) : null,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ],
        ]);

        return MatchOutcomeEvent::where('occurrence_key', $occurrenceKey)->first();
    }

    public function jobViewed(User $candidate, Job $job, ?int $rank = null): void
    {
        $snapshot = $this->snapshots->captureFromPersisted($candidate, $job, 'viewed', false);

        $this->record(MatchOutcomeEventType::JobViewed->value, [
            'candidate' => $candidate,
            'job' => $job,
            'context' => [
                'rank' => $rank,
                'snapshot' => $snapshot?->id,
            ],
        ]);
    }

    public function jobRecommended(User $candidate, Job $job, array $item, int $rank): void
    {
        $snapshot = $this->snapshots->captureRecommendation($candidate, $job, $item);

        $this->record(MatchOutcomeEventType::JobRecommended->value, [
            'candidate' => $candidate,
            'job' => $job,
            'context' => [
                'rank' => $rank,
                'snapshot' => $snapshot?->id,
            ],
        ]);
    }

    public function jobApplied(User $candidate, Job $job, ?JobApplication $application = null, int $score = 0): void
    {
        $snapshot = $this->snapshots->captureFromPersisted($candidate, $job, 'applied');

        $this->record(MatchOutcomeEventType::JobApplied->value, [
            'candidate' => $candidate,
            'job' => $job,
            'application' => $application,
            'context' => [
                'score' => $score,
                'snapshot' => $snapshot?->id,
            ],
        ]);
    }

    public function candidateProfileViewed(User $employer, User $candidate): void
    {
        $this->record(MatchOutcomeEventType::CandidateProfileViewed->value, [
            'candidate' => $candidate,
            'employer' => $employer,
        ]);
    }

    public function candidateShortlisted(User $employer, User $candidate, ?Job $job = null): void
    {
        $snapshot = null;

        if ($job) {
            $snapshot = $this->snapshots->captureFromPersisted($candidate, $job, 'manual');
        }

        $this->record(MatchOutcomeEventType::CandidateShortlisted->value, [
            'candidate' => $candidate,
            'job' => $job,
            'employer' => $employer,
            'context' => ['snapshot' => $snapshot?->id],
        ]);
    }

    public function contactMade(User $initiator, User $target, ?int $conversationId = null): void
    {
        if ($initiator->isEmployer()) {
            $eventType = MatchOutcomeEventType::EmployerContactedCandidate->value;
            $employer = $initiator;
            $candidate = $target;
        } else {
            $eventType = MatchOutcomeEventType::CandidateContactedEmployer->value;
            $employer = $target;
            $candidate = $initiator;
        }

        $this->record($eventType, [
            'candidate' => $candidate,
            'employer' => $employer,
            'context' => ['conversation_id' => $conversationId],
        ]);
    }

    public function applicationAdvanced(JobApplication $application, string $toStatus): void
    {
        $this->record(MatchOutcomeEventType::ApplicationAdvanced->value, [
            'candidate' => $application->candidate,
            'job' => $application->job,
            'application' => $application,
            'context' => ['to_status' => $toStatus],
        ]);
    }

    public function applicationRejected(JobApplication $application, string $reason = ''): void
    {
        $this->record(MatchOutcomeEventType::ApplicationRejected->value, [
            'candidate' => $application->candidate,
            'job' => $application->job,
            'application' => $application,
            'context' => ['reason' => $reason],
        ]);
    }

    public function hireRecorded(JobApplication $application): void
    {
        $this->record(MatchOutcomeEventType::HireRecorded->value, [
            'candidate' => $application->candidate,
            'job' => $application->job,
            'application' => $application,
        ]);
    }

    public function interviewScheduled(Interview $interview): void
    {
        $this->record(MatchOutcomeEventType::InterviewScheduled->value, [
            'candidate' => $interview->candidate,
            'job' => $interview->job ?? null,
            'employer' => $interview->employer,
            'interview' => $interview,
        ]);
    }

    public function interviewCompleted(Interview $interview): void
    {
        $this->record(MatchOutcomeEventType::InterviewCompleted->value, [
            'candidate' => $interview->candidate,
            'job' => $interview->job ?? null,
            'employer' => $interview->employer,
            'interview' => $interview,
        ]);
    }

    /**
     * Deterministic dedup key. Exposure events are bucketed per hour; richer
     * outcome events key on the owning ids so retries fold into one row.
     */
    protected function occurrenceKey(string $eventType, ?int $candidateId, ?int $jobId, ?int $employerId, $applicationId, $interviewId, array $context): string
    {
        $bucket = $eventType;

        // High-signal events: idempotent by their owning record.
        $keyed = match ($eventType) {
            'job_applied' => sprintf('%s:%s:%s', $bucket, $candidateId ?? 'c', $applicationId ?? $jobId ?? 'j'),
            'application_withdrawn', 'application_rejected', 'hire_recorded' => sprintf('%s:%s', $bucket, $applicationId ?? 'a'),
            'application_advanced' => sprintf('%s:%s:to:%s', $bucket, $applicationId ?? 'a', $context['to_status'] ?? '?'),
            'interview_scheduled', 'interview_completed' => sprintf('%s:%s', $bucket, $interviewId ?? 'i'),
            'candidate_shortlisted' => sprintf('%s:%s:%s:%s', $bucket, $employerId ?? 'e', $candidateId ?? 'c', $jobId ?? 'none'),
            'employer_contacted_candidate', 'candidate_contacted_employer' => sprintf('%s:%s:%s:%s', $bucket, $employerId ?? 'e', $candidateId ?? 'c', $context['conversation_id'] ?? 'none'),
            default => sprintf('%s:%s:%s:%s', $bucket, $candidateId ?? 'c', $jobId ?? 'j', now()->format('YmdH')),
        };

        return Str::limit($keyed, 180, '');
    }
}
