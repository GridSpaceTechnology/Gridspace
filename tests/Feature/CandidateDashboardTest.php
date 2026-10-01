<?php

use App\Models\CandidateProfile;
use App\Models\EmployerCultureProfile;
use App\Models\Job;
use App\Models\User;
use App\Services\MatchChecksumService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function dashboardEmployerWithCulture(): User
{
    $employer = User::factory()->create(['role' => 'employer']);

    EmployerCultureProfile::create([
        'user_id' => $employer->id,
        'leadership_style' => 'coaching',
        'communication_style' => 'direct',
        'innovation_level' => 'high',
        'decision_making_style' => 'collaborative',
        'work_pace' => 'fast',
        'collaboration_level' => 'high',
        'company_pace' => 'fast',
        'work_environment' => 'remote',
        'independence_level' => 'high',
    ]);

    return $employer;
}

function dashboardJob(User $employer): Job
{
    return Job::create([
        'employer_id' => $employer->id,
        'title' => 'Senior Backend Engineer',
        'role' => 'Backend Developer',
        'slug' => Str::random(10),
        'employment_type' => 'full_time',
        'work_preference' => 'remote',
        'status' => 'open',
        'required_skills_json' => ['PHP', 'Laravel'],
    ]);
}

function dashboardCandidate(): User
{
    $candidate = User::factory()->create(['role' => 'candidate', 'onboarding_completed' => false]);

    CandidateProfile::create([
        'user_id' => $candidate->id,
        'desired_role' => 'Backend Developer',
        'years_of_experience' => 4,
        'work_preference' => 'remote',
    ]);
    $candidate->candidateSkills()->create(['skill_name' => 'PHP', 'proficiency_level' => 4]);
    $candidate->candidateAssessment()->create(['skill_score' => 80]);
    $candidate->personalityProfile()->create(['assessment_completed' => true]);

    return $candidate;
}

test('candidate who uploads a cv in onboarding can open the dashboard', function () {
    $this->withoutVite();
    Storage::fake('public');

    $employer = dashboardEmployerWithCulture();
    dashboardJob($employer);
    dashboardJob($employer);
    $candidate = dashboardCandidate();

    $this->actingAs($candidate)->post(route('candidate.onboarding.store', ['step' => 8]), [
        'cv_path' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        'linkedin_url' => 'https://linkedin.com/in/me',
    ])->assertRedirect();

    $candidate->forceFill(['onboarding_completed' => true])->save();

    $this->actingAs($candidate)->get(route('candidate.dashboard'))->assertOk();
});

test('match checksum includes the employer culture profile', function () {
    $employer = User::factory()->create(['role' => 'employer']);
    $job = dashboardJob($employer);
    $candidate = dashboardCandidate();

    $checksums = app(MatchChecksumService::class);
    $withoutCulture = $checksums->for($candidate, $job);

    EmployerCultureProfile::create([
        'user_id' => $employer->id,
        'leadership_style' => 'coaching',
        'communication_style' => 'direct',
        'innovation_level' => 'high',
        'decision_making_style' => 'collaborative',
        'work_pace' => 'fast',
        'collaboration_level' => 'high',
        'company_pace' => 'fast',
    ]);

    expect($checksums->for($candidate, $job))->not->toBe($withoutCulture);
});
