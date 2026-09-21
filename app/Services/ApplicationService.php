<?php

namespace App\Services;

use App\Jobs\SendNewApplicationNotification;
use App\Models\Job;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JobApplicationService
{
    private MatchService $matchService;

    public function __construct(MatchService $matchService)
    {
        $this->matchService = $matchService;
    }

    public function canApply(User $candidate, Job $job): array
    {
        $reasons = [];

        if ($candidate->role !== 'candidate') {
            $reasons[] = 'Only candidates can apply';
        }

        if (! $candidate->onboarding_completed) {
            $reasons[] = 'Complete onboarding before applying';
        }

        if ($job->status !== 'open') {
            $reasons[] = 'This job is not accepting applications';
        }

        if ($job->employer_id === $candidate->id) {
            $reasons[] = 'Cannot apply to your own job posting';
        }

        $existing = $this->getExistingJobApplication($candidate, $job);
        if ($existing) {
            $reasons[] = 'Already applied to this job';
        }

        return [
            'can_apply' => empty($reasons),
            'reasons' => $reasons,
        ];
    }

    public function createJobApplication(User $candidate, Job $job, ?string $note = null): JobApplication
    {
        $application = DB::transaction(function () use ($candidate, $job, $note) {
            $matchData = $this->calculateMatchData($candidate, $job);

            $application = JobApplication::create([
                'job_id' => $job->id,
                'candidate_id' => $candidate->id,
                'status' => 'applied',
                'match_score' => $matchData['score'],
                'candidate_note' => $note,
                'applied_at' => now(),
            ]);

            $job->increment('applications_count');

            return $application;
        });

        SendNewApplicationNotification::notify($application);

        return $application;
    }

    public function calculateMatchData(User $candidate, Job $job): array
    {
        $score = $this->matchService->calculateMatchScore($candidate, $job);

        $matchedSkills = $this->getMatchedSkills($candidate, $job);
        $missingSkills = $this->getMissingSkills($candidate, $job);

        return [
            'score' => $score,
            'matched_skills' => $matchedSkills,
            'missing_skills' => $missingSkills,
            'experience_match' => $this->checkExperienceMatch($candidate, $job),
            'salary_match' => $this->checkSalaryMatch($candidate, $job),
            'work_preference_match' => $this->checkWorkPreferenceMatch($candidate, $job),
        ];
    }

    public function getExistingJobApplication(User $candidate, Job $job): ?JobApplication
    {
        return JobApplication::where('job_id', $job->id)
            ->where('candidate_id', $candidate->id)
            ->first();
    }

    public function withdrawJobApplication(User $candidate, Job $job): bool
    {
        $application = $this->getExistingJobApplication($candidate, $job);

        if (! $application) {
            return false;
        }

        if (! in_array($application->status, ['applied', 'viewed', 'shortlisted'])) {
            return false;
        }

        $application->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);

        return true;
    }

    private function getMatchedSkills(User $candidate, Job $job): array
    {
        $candidateSkills = $candidate->candidateSkills()
            ->whereNotNull('skill_id')
            ->pluck('skill_id')
            ->toArray();

        $requiredSkills = $job->jobSkills()
            ->where('is_required', true)
            ->pluck('skill_id')
            ->toArray();

        return array_intersect($candidateSkills, $requiredSkills);
    }

    private function getMissingSkills(User $candidate, Job $job): array
    {
        $candidateSkills = $candidate->candidateSkills()
            ->whereNotNull('skill_id')
            ->pluck('skill_id')
            ->toArray();

        $requiredSkills = $job->jobSkills()
            ->where('is_required', true)
            ->pluck('skill_id')
            ->toArray();

        return array_diff($requiredSkills, $candidateSkills);
    }

    private function checkExperienceMatch(User $candidate, Job $job): bool
    {
        $profile = $candidate->candidateProfile;
        if (! $profile) {
            return false;
        }

        return $profile->years_of_experience >= ($job->minimum_experience ?? 0);
    }

    private function checkSalaryMatch(User $candidate, Job $job): bool
    {
        $profile = $candidate->candidateProfile;
        if (! $profile || ! $profile->salary_expectation) {
            return true;
        }

        $maxSalary = $job->salary_max;
        if (! $maxSalary) {
            return true;
        }

        return $profile->salary_expectation <= $maxSalary;
    }

    private function checkWorkPreferenceMatch(User $candidate, Job $job): bool
    {
        $profile = $candidate->candidateProfile;
        if (! $profile || ! $profile->work_preference) {
            return true;
        }

        return $profile->work_preference === $job->work_preference;
    }
}
