<?php

namespace App\Services;

use App\Models\EmployerCultureProfile;
use App\Models\Job;
use App\Models\JobRequirement;
use App\Models\JobSkill;
use App\Models\User;

/**
 * Deterministic fingerprint of every input that influences a candidate-job
 * match. Used to detect stale persisted scores: when the stored checksum no
 * longer equals the freshly hashed candidate/job data, the persisted row must
 * be recomputed.
 *
 * The same inputs always produce the same checksum within the same algorithm
 * version. Ordered collections are sorted and floats are formatted in a
 * locale-independent way before hashing so byte-identical data stays stable.
 */
class MatchChecksumService
{
    public function for(User $candidate, Job $job): string
    {
        // Always hash the persisted state: in-memory models can lag the
        // database (e.g. column defaults applied only on write), which would
        // otherwise make the same pair hash differently at save vs. read time.
        $candidate = $candidate->fresh() ?? $candidate;
        $job = $job->fresh() ?? $job;

        $payload = [
            'version' => (int) config('matching.algorithm_version', 0),
            'candidate' => $this->candidateFacts($candidate),
            'job' => $this->jobFacts($job),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function candidateFacts(User $candidate): array
    {
        $profile = $candidate->candidateProfile;

        $skills = $candidate->candidateSkills()
            ->get()
            ->map(fn ($skill) => [
                'name' => $this->normalize($skill->skill_name ?? $skill->skill?->name),
                'proficiency' => (int) ($skill->proficiency_level ?? 0),
            ])
            ->filter(fn (array $entry) => $entry['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();

        $education = $candidate->candidateEducation()
            ->pluck('qualification')
            ->filter()
            ->map(fn ($value) => mb_strtolower((string) $value))
            ->sort()
            ->values()
            ->all();

        $personality = $candidate->personalityProfile;

        return [
            'desired_role' => (string) ($profile?->desired_role ?? ''),
            'current_role' => (string) ($profile?->current_role ?? ''),
            'industry' => (string) ($profile?->industry ?? ''),
            'years_of_experience' => (int) ($profile?->years_of_experience ?? 0),
            'work_preference' => (string) ($profile?->work_preference ?? ''),
            'salary_expectation' => (string) ($profile?->salary_expectation ?? ''),
            'availability' => (string) ($profile?->availability ?? ''),
            'location_country' => (string) ($profile?->location_country ?? ''),
            'skills' => $skills,
            'education' => $education,
            'temperament_type' => (string) ($personality?->temperament_type ?? ''),
            'assessment_completed' => (bool) ($personality?->assessment_completed ?? false),
            'dimension_scores' => $this->sortedMap((array) ($personality?->dimension_scores ?? [])),
            'work_style' => (string) ($personality?->work_style ?? ''),
            'organizational_fit' => (string) ($personality?->organizational_fit ?? ''),
            'collaboration_style' => (string) ($personality?->collaboration_style ?? ''),
        ];
    }

    private function jobFacts(Job $job): array
    {
        $jobSkills = $job->jobSkills()
            ->with('skill')
            ->get()
            ->map(fn (JobSkill $skill) => [
                'name' => $this->normalize($skill->skill?->name ?? (string) $skill->skill_id),
                'required' => (bool) $skill->is_required,
                'min_proficiency' => (int) ($skill->min_proficiency ?? 1),
            ])
            ->filter(fn (array $entry) => $entry['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();

        $requirements = $job->jobRequirements()
            ->get()
            ->sortBy('requirement_type')
            ->map(fn (JobRequirement $requirement) => [
                'type' => (string) $requirement->requirement_type,
                'value' => (string) $requirement->requirement_value,
                'mandatory' => (bool) $requirement->is_mandatory,
            ])
            ->values()
            ->all();

        $culture = EmployerCultureProfile::where('user_id', $job->employer_id)->first();

        return [
            'title' => (string) $job->title,
            'role' => (string) $job->role,
            'industry' => (string) $job->industry,
            'work_preference' => (string) $job->work_preference,
            'salary_min' => is_null($job->salary_min) ? '' : (string) $job->salary_min,
            'salary_max' => is_null($job->salary_max) ? '' : (string) $job->salary_max,
            'salary_currency' => (string) $job->salary_currency,
            'minimum_experience' => (string) $job->minimum_experience,
            'experience_level' => (string) $job->experience_level,
            'location_country' => (string) $job->location_country,
            'required_skills_json' => $this->sortedList($job->getRequiredSkills()),
            'personality_preferences_json' => $this->sortedMap((array) $job->personality_preferences_json),
            'temperament_preference' => (string) $job->temperament_preference,
            'job_skills' => $jobSkills,
            'requirements' => $requirements,
            'culture' => $culture ? [
                'company_pace' => (string) $culture->company_pace,
                'work_environment' => (string) $culture->work_environment,
                'independence_level' => (string) $culture->independence_level,
            ] : null,
        ];
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function sortedList(array $values): array
    {
        $normalized = array_values(array_filter(array_map(
            fn ($value) => $this->normalize($value),
            $values
        )));

        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function sortedMap(array $values): array
    {
        ksort($values, SORT_STRING);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sortedMap($value);
            }
        }

        return $values;
    }
}
