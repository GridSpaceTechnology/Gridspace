<?php

namespace App\Enums;

enum MatchOutcomeEventType: string
{
    case JobRecommended = 'job_recommended';
    case JobViewed = 'job_viewed';
    case CandidateProfileViewed = 'candidate_profile_viewed';
    case JobApplied = 'job_applied';
    case ApplicationWithdrawn = 'application_withdrawn';
    case ApplicationRejected = 'application_rejected';
    case ApplicationAdvanced = 'application_advanced';
    case CandidateShortlisted = 'candidate_shortlisted';
    case HireRecorded = 'hire_recorded';
    case EmployerContactedCandidate = 'employer_contacted_candidate';
    case CandidateContactedEmployer = 'candidate_contacted_employer';
    case InterviewScheduled = 'interview_scheduled';
    case InterviewCompleted = 'interview_completed';

    public function category(): string
    {
        return (string) data_get(config('matching.outcome_events'), "{$this->value}.category", 'exposure');
    }

    public function weight(): int
    {
        return (int) data_get(config('matching.outcome_events'), "{$this->value}.weight", 1);
    }

    public static function valid(string $value): bool
    {
        return in_array($value, array_column(self::cases(), 'value'), true);
    }
}
