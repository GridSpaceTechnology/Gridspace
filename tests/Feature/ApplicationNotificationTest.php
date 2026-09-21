<?php

use App\Jobs\RecalculateCandidateMatches;
use App\Jobs\SendNewApplicationNotification;
use App\Mail\NewApplicationNotification;
use App\Models\CandidateMedia;
use App\Models\CandidatePersonalityProfile;
use App\Models\CandidateProfile;
use App\Models\CandidateSkill;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\MatchingEngineService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function notificationEmployer(): User
{
    $user = User::factory()->create([
        'role' => 'employer',
        'name' => 'Hiring Manager',
        'email' => 'employer-'.Str::random(6).'@example.com',
    ]);

    Company::create([
        'user_id' => $user->id,
        'name' => 'Northwind Ltd',
        'slug' => 'northwind-'.uniqid(),
        'allow_candidate_messages' => true,
    ]);

    return $user;
}

function notificationJob(User $employer, array $attributes = []): Job
{
    return Job::create([
        'employer_id' => $employer->id,
        'title' => 'Senior Backend Engineer',
        'role' => 'Backend Developer',
        'slug' => Str::random(10),
        'employment_type' => 'full_time',
        'work_preference' => 'remote',
        'salary_min' => 500000,
        'salary_max' => 900000,
        'salary_currency' => 'NGN',
        'status' => 'open',
        'required_skills_json' => ['PHP', 'Laravel', 'MySQL'],
        ...$attributes,
    ]);
}

function notificationCandidate(bool $withProfile = true): User
{
    $user = User::factory()->create([
        'role' => 'candidate',
        'name' => 'Benjamin Nwaochei',
        'email' => 'benjamin-'.Str::random(6).'@example.com',
        'onboarding_completed' => true,
    ]);

    if ($withProfile) {
        CandidateProfile::create([
            'user_id' => $user->id,
            'current_role' => 'Backend Developer',
            'desired_role' => 'Backend Developer',
            'years_of_experience' => 4,
            'salary_expectation' => 700000,
            'work_preference' => 'remote',
            'location_country' => 'Nigeria',
            'availability' => '2_weeks',
        ]);

        foreach (['PHP', 'Laravel', 'MySQL'] as $skillName) {
            CandidateSkill::create([
                'user_id' => $user->id,
                'skill_name' => $skillName,
                'proficiency_level' => 3,
            ]);
        }
    }

    return $user;
}

function attachNotificationPersonality(User $candidate): CandidatePersonalityProfile
{
    return CandidatePersonalityProfile::create([
        'candidate_id' => $candidate->id,
        'work_style' => 'Structured and plan-driven',
        'communication_style' => 'Clear and direct',
        'collaboration_style' => 'Collaborative',
        'leadership_style' => 'Leads by example',
        'motivation_type' => 'Impact-driven',
        'temperament_type' => 'Analytical',
        'personality_summary' => 'A meticulous engineer who thrives in structured teams.',
        'assessment_completed' => true,
        'completed_at' => now(),
    ]);
}

function attachNotificationCv(User $candidate): void
{
    Storage::fake('public');

    $path = "cvs/{$candidate->id}/resume.pdf";

    Storage::disk('public')->put($path, 'fake cv content');

    CandidateMedia::create([
        'user_id' => $candidate->id,
        'cv_path' => $path,
    ]);
}

function createNotificationApplication(User $candidate, Job $job, array $attributes = []): JobApplication
{
    return JobApplication::create([
        'job_id' => $job->id,
        'candidate_id' => $candidate->id,
        'status' => JobApplication::STATUS_APPLIED,
        'match_score' => $attributes['match_score'] ?? 85,
        'candidate_note' => $attributes['candidate_note'] ?? null,
        'applied_at' => now(),
    ]);
}

it('emails the job owner when a candidate applies through the web flow', function () {
    Mail::fake();

    $employer = notificationEmployer();
    $otherEmployer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('applications', [
        'candidate_id' => $candidate->id,
        'job_id' => $job->id,
        'status' => JobApplication::STATUS_APPLIED,
    ]);

    Mail::assertSent(NewApplicationNotification::class, function (NewApplicationNotification $mail) use ($candidate, $employer, $job) {
        return $mail->hasTo($employer->email)
            && $mail->job->is($job)
            && $mail->candidate->is($candidate)
            && $mail->application->candidate_id === $candidate->id
            && str_contains($mail->envelope()->subject, $job->title)
            && str_contains($mail->envelope()->subject, $candidate->name)
            && $mail->hasCv === false;
    });

    Mail::assertNotSent(NewApplicationNotification::class, fn (NewApplicationNotification $mail) => $mail->hasTo($otherEmployer->email));
});

it('includes candidate, match, personality and cover letter content in the email', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    attachNotificationPersonality($candidate);
    attachNotificationCv($candidate);
    $job = notificationJob($employer);

    $application = createNotificationApplication($candidate, $job, [
        'candidate_note' => "I would love to join your team.\nI bring four years of Laravel experience.",
    ]);

    $mail = new NewApplicationNotification($application);

    $html = $mail->render();

    expect($mail->hasCv)->toBeTrue();
    expect($mail->match['overall'])->toBeGreaterThan(0);
    expect((string) $mail->coverLetter)->toContain('I would love to join your team.');
    expect($html)->toContain($candidate->name);
    expect($html)->toContain('Backend Developer');
    expect($html)->toContain('I would love to join your team.');
    expect($html)->toContain('I bring four years of Laravel experience.');
    expect($html)->toContain('Match Analysis');
    expect($html)->toContain('Structured and plan-driven');
    expect($html)->toContain('A meticulous engineer');
    expect($html)->toContain('View Full Application');
    expect($html)->toContain(route('employer.ats.show', $application));
});

it('attaches the candidate CV to the email with a professional filename', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    attachNotificationCv($candidate);
    $job = notificationJob($employer);

    $application = createNotificationApplication($candidate, $job);

    $mail = new NewApplicationNotification($application);

    $attachments = $mail->attachments();

    expect($mail->hasCv)->toBeTrue();
    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->as)->toBe('benjamin-nwaochei-CV.pdf');
});

it('sends the email without an attachment when the candidate has no CV', function () {
    Mail::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    Mail::assertSent(NewApplicationNotification::class, function (NewApplicationNotification $mail) {
        $attachments = $mail->attachments();

        return $mail->hasCv === false
            && $attachments === []
            && str_contains($mail->render(), 'No CV was attached to this application.');
    });
});

it('includes the cover letter absence message when no cover letter is provided', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $mail = new NewApplicationNotification(createNotificationApplication($candidate, $job));

    expect($mail->coverLetter)->toBeNull();
    expect($mail->render())->toContain('No cover letter was provided.');
});

it('sends the notification even when the candidate has no personality data', function () {
    Mail::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    Mail::assertSent(NewApplicationNotification::class, function (NewApplicationNotification $mail) {
        return $mail->personality === null
            && str_contains($mail->render(), 'Personality assessment information is not available for this candidate.');
    });
});

it('sends the notification even when no match data is available', function () {
    Mail::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate(withProfile: false);
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    Mail::assertSent(NewApplicationNotification::class, function (NewApplicationNotification $mail) {
        return $mail->match['overall'] === 0
            && str_contains($mail->render(), 'Match analysis is currently unavailable.');
    });
});

it('queues the notification instead of delivering email inside the apply request', function () {
    Queue::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    Queue::assertPushed(SendNewApplicationNotification::class, 1);
});

it('keeps the application successful when email delivery fails', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $application = createNotificationApplication($candidate, $job);

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP connection refused'));

    $jobInstance = new SendNewApplicationNotification($application);

    $this->expectException(RuntimeException::class);
    $jobInstance->handle();

    $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => JobApplication::STATUS_APPLIED]);
});

it('logs and forgets permanent notification failures', function () {
    Log::shouldReceive('error')->once();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $jobInstance = new SendNewApplicationNotification(createNotificationApplication($candidate, $job));

    $jobInstance->failed(new RuntimeException('SMTP permanently down'));
});

it('does not notify on unrelated activity such as page loads or match recalculation', function () {
    Queue::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->get(route('candidate.jobs'))
        ->assertOk();

    (new RecalculateCandidateMatches($candidate))->handle(app(MatchingEngineService::class));

    Queue::assertNotPushed(SendNewApplicationNotification::class);
});

it('does not send a duplicate notification for duplicate submissions', function () {
    Queue::fake();

    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id))
        ->assertSessionHas('success');

    $this->actingAs($candidate)
        ->post(route('candidate.jobs.apply', $job->id));

    $this->assertSame(1, JobApplication::query()
        ->where('candidate_id', $candidate->id)
        ->where('job_id', $job->id)
        ->count());

    Queue::assertPushed(SendNewApplicationNotification::class, 1);
});

it('allows the job-owning employer to open the full application page', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);
    $application = createNotificationApplication($candidate, $job);

    $this->actingAs($employer)
        ->get(route('employer.ats.show', $application))
        ->assertOk();
});

it('prevents a candidate from opening the employer application page', function () {
    $employer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($employer);
    $application = createNotificationApplication($candidate, $job);

    $this->actingAs($candidate)
        ->get(route('employer.ats.show', $application))
        ->assertForbidden();
});

it('prevents another employer from viewing an application for a job they do not own', function () {
    $owner = notificationEmployer();
    $otherEmployer = notificationEmployer();
    $candidate = notificationCandidate();
    $job = notificationJob($owner);
    $application = createNotificationApplication($candidate, $job);

    $this->actingAs($otherEmployer)
        ->get(route('employer.ats.show', $application))
        ->assertForbidden();
});
