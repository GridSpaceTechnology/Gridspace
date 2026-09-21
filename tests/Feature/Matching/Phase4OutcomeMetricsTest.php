<?php

use App\Models\CandidateRecommendationFeedback;
use App\Models\Conversation;
use App\Models\EmployerRecommendationFeedback;
use App\Models\EmployerShortlist;
use App\Models\JobApplication;
use App\Models\MatchOutcomeEvent;
use App\Models\MatchSnapshot;
use App\Services\CandidateFeedbackSignalService;
use App\Services\JobMatchingService;
use App\Services\MatchAnalyticsService;
use App\Services\MatchingEngineService;
use App\Services\MatchOutcomeService;
use App\Services\MatchSnapshotService;
use Carbon\Carbon;

/**
 * Phase 4 outcome metric system: event recording + idempotency, snapshot
 * capture policy, candidate feedback gating, bounded negative signals, and the
 * analytics reports that back human-controlled calibration.
 *
 * Synthetic data only (no real personal data). Uses the phase4* helpers defined
 * in GoldenMatchingCasesTest.php.
 */
beforeEach(function () {
    $this->phase4OutcomeConfig = [
        'matching.recommendation.negative_signal' => config('matching.recommendation.negative_signal'),
        'matching.metrics.min_sample' => config('matching.metrics.min_sample'),
        'matching.algorithm_version' => config('matching.algorithm_version'),
    ];

    config([
        'matching.algorithm_version' => 3,
        'matching.metrics.min_sample' => 20,
    ]);

    Carbon::setTestNow();
});

afterEach(function () {
    config($this->phase4OutcomeConfig);
    Carbon::setTestNow();
});

it('records a job_viewed event and folds repeat views within the hour into one row', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());
    $svc = app(MatchOutcomeService::class);

    $svc->jobViewed($candidate, $job);
    $svc->jobViewed($candidate, $job);

    expect(MatchOutcomeEvent::where('event_type', 'job_viewed')->count())->toBe(1)
        ->and(MatchOutcomeEvent::where('event_type', 'job_viewed')->first()->event_category)->toBe('exposure');
});

it('records a new job_viewed row when a later hour bucket begins', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());
    $svc = app(MatchOutcomeService::class);

    $svc->jobViewed($candidate, $job);

    Carbon::setTestNow(now()->addHour()->addMinute());

    $svc->jobViewed($candidate, $job);

    expect(MatchOutcomeEvent::where('event_type', 'job_viewed')->count())->toBe(2);
});

it('records a single job_applied event per application even when retried', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);
    $job = phase4Job(phase4Employer());
    $application = JobApplication::create([
        'job_id' => $job->id,
        'candidate_id' => $candidate->id,
        'status' => JobApplication::STATUS_APPLIED,
        'applied_at' => now(),
    ]);
    $svc = app(MatchOutcomeService::class);

    $svc->jobApplied($candidate, $job, $application);
    $svc->jobApplied($candidate, $job, $application);
    $svc->jobApplied($candidate, $job, $application);

    expect(MatchOutcomeEvent::where('event_type', 'job_applied')->count())->toBe(1);
});

it('records a job_recommended event with rank context and algorithmic version', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $job = phase4Job(phase4Employer());

    $item = collect(phase4RecommendationItems($candidate))->firstWhere('job.id', $job->id);

    app(MatchOutcomeService::class)->jobRecommended($candidate, $job, $item, 1);

    $event = MatchOutcomeEvent::where('event_type', 'job_recommended')->first();

    expect($event->context['rank'])->toBe(1)
        ->and((int) $event->algorithm_version)->toBe(3)
        ->and($event->event_category)->toBe('exposure');
});

it('ignores unknown event types without throwing', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());

    $result = app(MatchOutcomeService::class)->record('not_a_real_event', [
        'candidate' => $candidate,
        'job' => $job,
    ]);

    expect($result)->toBeNull()
        ->and(MatchOutcomeEvent::count())->toBe(0);
});

it('captures a full component snapshot on apply even without a persisted score row', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $job = phase4Job(phase4Employer());

    app(MatchOutcomeService::class)->jobApplied($candidate, $job);

    $snapshot = MatchSnapshot::where('candidate_id', $candidate->id)
        ->where('job_id', $job->id)
        ->where('source', MatchSnapshotService::SOURCE_APPLIED)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and((int) $snapshot->data_checksum)->not->toBe('')
        ->and($snapshot->matched_skills)->toContain('PHP')
        ->and((int) $snapshot->skills_score)->toBeGreaterThan(0);
});

it('reuses a persisted score row for the cheap viewed snapshot instead of recomputing', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);
    $job = phase4Job(phase4Employer());

    $persisted = app(MatchingEngineService::class)->saveMatch($candidate, $job);

    $snapshot = app(MatchSnapshotService::class)->captureFromPersisted(
        $candidate,
        $job,
        MatchSnapshotService::SOURCE_VIEWED,
        allowRecompute: false,
    );

    expect($snapshot)->not->toBeNull()
        ->and((int) $snapshot->profile_match_score)->toBe((int) $persisted->overall_match_score)
        ->and($snapshot->source)->toBe('viewed');
});

it('skips the viewed snapshot on a hot path when no persisted row exists', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());

    $snapshot = app(MatchSnapshotService::class)->captureFromPersisted(
        $candidate,
        $job,
        MatchSnapshotService::SOURCE_VIEWED,
        allowRecompute: false,
    );

    expect($snapshot)->toBeNull()
        ->and(MatchSnapshot::count())->toBe(0);
});

it('keeps exactly one snapshot per candidate job source and version', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);
    $job = phase4Job(phase4Employer());
    $svc = app(MatchSnapshotService::class);

    $svc->capture($candidate, $job, MatchSnapshotService::SOURCE_MANUAL);
    $svc->capture($candidate, $job, MatchSnapshotService::SOURCE_MANUAL);

    expect(MatchSnapshot::where('source', MatchSnapshotService::SOURCE_MANUAL)->count())->toBe(1);
});

it('stores every component score inside the valid 0-100 range', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);
    $job = phase4Job(phase4Employer());

    $snapshot = app(MatchSnapshotService::class)->capture($candidate, $job, MatchSnapshotService::SOURCE_MANUAL);

    foreach ([
        $snapshot->profile_match_score,
        $snapshot->recommendation_score,
        $snapshot->skills_score,
        $snapshot->role_score,
        $snapshot->experience_score,
        $snapshot->personality_score,
        $snapshot->work_preference_score,
        $snapshot->salary_score,
        $snapshot->education_score,
        $snapshot->availability_score,
    ] as $score) {
        expect($score)->toBeGreaterThanOrEqual(0)
            ->and($score)->toBeLessThanOrEqual(100);
    }
});

it('applies the negative signal cap and never lowers the profile score', function () {
    config([
        'matching.recommendation.negative_signal.enabled' => true,
        'matching.recommendation.negative_signal.penalty_points' => 40,
        'matching.recommendation.negative_signal.penalty_cap' => 30,
        'matching.recommendation.negative_signal.domain_decay' => 0.5,
        'matching.recommendation.negative_signal.half_life_days' => 14,
    ]);

    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $job = phase4Job(phase4Employer());

    $items = phase4RecommendationItems($candidate);
    $before = collect($items)->firstWhere('job.id', $job->id);

    CandidateRecommendationFeedback::create([
        'candidate_id' => $candidate->id,
        'job_id' => $job->id,
        'feedback_type' => 'not_interested',
        'is_relevant' => false,
        'feedback_key' => "c:{$candidate->id}:{$job->id}",
    ]);

    $after = collect(phase4RecommendationItems($candidate))->firstWhere('job.id', $job->id);

    expect($after['recommendation_score'])->toBe($before['profile_match_score'] - 30)
        ->and($after['recommendation_score'])->toBeLessThan($before['recommendation_score'])
        ->and($after['profile_match_score'])->toBe($before['profile_match_score'])
        ->and(app(CandidateFeedbackSignalService::class)->totalPenalty($candidate, $job))->toBe(30);
});

it('halves a dismissal penalty after one half-life has elapsed', function () {
    config([
        'matching.recommendation.negative_signal.enabled' => true,
        'matching.recommendation.negative_signal.penalty_points' => 20,
        'matching.recommendation.negative_signal.penalty_cap' => 30,
        'matching.recommendation.negative_signal.half_life_days' => 14,
    ]);

    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $job = phase4Job(phase4Employer());

    Carbon::setTestNow(now()->subDays(14));

    CandidateRecommendationFeedback::create([
        'candidate_id' => $candidate->id,
        'job_id' => $job->id,
        'feedback_type' => 'not_relevant',
        'is_relevant' => false,
        'feedback_key' => "c:{$candidate->id}:{$job->id}",
    ]);

    Carbon::setTestNow();

    $items = phase4RecommendationItems($candidate);
    $after = collect($items)->firstWhere('job.id', $job->id);

    expect($after['recommendation_score'])->toBe($after['profile_match_score'] - 10);
});

it('never lets candidate dismissal feedback alter employer-facing ranking', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $job = phase4Job(phase4Employer());

    $breakdown = app(JobMatchingService::class)->calculateBreakdown($candidate, $job);

    CandidateRecommendationFeedback::create([
        'candidate_id' => $candidate->id,
        'job_id' => $job->id,
        'feedback_type' => 'wrong_role',
        'is_relevant' => false,
        'feedback_key' => "c:{$candidate->id}:{$job->id}",
    ]);

    $signal = app(CandidateFeedbackSignalService::class);

    expect($signal->totalPenalty($candidate, $job))->toBeGreaterThan(0)
        ->and($signal->hasNegativeSignals($candidate))->toBeTrue();

    $ranked = app(JobMatchingService::class)->rankCandidatesForJob($job)
        ->getCollection()
        ->first(fn (array $item) => $item['candidate']->id === $candidate->id);

    expect($ranked)->not->toBeNull()
        ->and($ranked['overall_score'])->toBe($breakdown['overall_score'])
        ->and($ranked['recommendation_score'])->toBe($breakdown['recommendation_score']);
});

it('keeps the domain gate authoritative even under heavy negative feedback', function () {
    $candidate = phase4Candidate(['desired_role' => 'Software Engineer'], ['PHP']);
    $accounting = phase4Job(phase4Employer(), [
        'title' => 'Accountant',
        'role' => 'Accounting',
        'required_skills_json' => ['Bookkeeping'],
    ]);

    CandidateRecommendationFeedback::create([
        'candidate_id' => $candidate->id,
        'job_id' => $accounting->id,
        'feedback_type' => 'not_relevant',
        'is_relevant' => false,
        'feedback_key' => "c:{$candidate->id}:{$accounting->id}",
    ]);

    $item = collect(phase4RecommendationItems($candidate))
        ->firstWhere('job.id', $accounting->id);

    $profile = collect(phase4RecommendationItems($candidate))
        ->firstWhere('job.id', $accounting->id);

    expect($item['match_status'])->toBe('incompatible')
        ->and($item['recommendation_score'])->toBeLessThanOrEqual(config('matching.domain_gate_cap'))
        ->and($profile['profile_match_score'])->toBe(config('matching.domain_gate_cap'));
});

it('suppresses analytics outputs below the configured minimum sample', function () {
    $candidate = phase4Candidate();
    $employer = phase4Employer();
    $svc = app(MatchOutcomeService::class);

    foreach ([1, 2, 3, 4, 5] as $i) {
        $svc->jobViewed($candidate, phase4Job($employer, ['slug' => "s{$i}".str()->random(6)]));
    }

    config(['matching.metrics.min_sample' => 1000]);

    expect(app(MatchAnalyticsService::class)->sampleSatisfied())->toBeFalse();

    config(['matching.metrics.min_sample' => 5]);

    expect(app(MatchAnalyticsService::class)->sampleSatisfied())->toBeTrue();
});

it('computes per-band apply rates from snapshot-linked outcome events', function () {
    $candidate = phase4Candidate();
    $employer = phase4Employer();
    $svc = app(MatchSnapshotService::class);
    $outcomes = app(MatchOutcomeService::class);

    $highJob = phase4Job($employer, ['slug' => 'high'.str()->random(6)]);
    $midJob = phase4Job($employer, ['title' => 'Platform Engineer', 'slug' => 'mid'.str()->random(6)]);

    $highSnapshot = $svc->capture($candidate, $highJob, MatchSnapshotService::SOURCE_RECOMMENDED, ['overall_score' => 85, 'recommendation_score' => 85]);
    $midSnapshot = $svc->capture($candidate, $midJob, MatchSnapshotService::SOURCE_RECOMMENDED, ['overall_score' => 65, 'recommendation_score' => 65]);

    $outcomes->record('job_applied', [
        'candidate' => $candidate,
        'job' => $highJob,
        'context' => ['snapshot' => $highSnapshot->id],
    ]);

    $report = app(MatchAnalyticsService::class)->bandsReport();

    expect($report['80-89']['exposed'])->toBe(1)
        ->and($report['80-89']['applied'])->toBe(1)
        ->and($report['80-89']['apply_rate'])->toBe(100.0)
        ->and($report['60-69']['exposed'])->toBe(1)
        ->and($report['60-69']['applied'])->toBe(0)
        ->and($report['60-69']['apply_rate'])->toBe(0.0);
});

it('computes precision@K and recall@K over recommendation exposure', function () {
    $candidate = phase4Candidate();
    $employer = phase4Employer();
    $svc = app(MatchSnapshotService::class);
    $outcomes = app(MatchOutcomeService::class);

    $scores = ['high' => 95, 'mid' => 90, 'low' => 85, 'lowest' => 80];
    $snapshots = [];

    foreach ($scores as $title => $score) {
        $job = phase4Job($employer, ['title' => $title, 'slug' => $title.str()->random(4)]);
        $snapshots[$title] = $svc->capture(
            $candidate,
            $job,
            MatchSnapshotService::SOURCE_RECOMMENDED,
            ['overall_score' => $score, 'recommendation_score' => $score],
        );
    }

    $outcomes->record('job_applied', [
        'candidate' => $candidate,
        'job' => $snapshots['mid']->job,
        'context' => ['snapshot' => $snapshots['mid']->id],
    ]);

    $report = app(MatchAnalyticsService::class)->precisionRecallK(2, 'applied');

    expect($report['candidates'])->toBe(1)
        ->and($report['precision_at_k'])->toBe(0.5)
        ->and($report['recall_at_k'])->toBe(1.0);
});

it('groups snapshot usage by algorithm version', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());
    $svc = app(MatchSnapshotService::class);

    config(['matching.algorithm_version' => 3]);
    $svc->capture($candidate, $job, MatchSnapshotService::SOURCE_MANUAL);

    config(['matching.algorithm_version' => 2]);
    $second = phase4Job(phase4Employer(), ['slug' => 'v2'.str()->random(6)]);
    $svc->capture($candidate, $second, MatchSnapshotService::SOURCE_MANUAL);

    $usage = app(MatchAnalyticsService::class)->versionUsage();

    expect($usage->pluck('version')->sort()->values()->all())->toBe([2, 3])
        ->and($usage->pluck('label')->sort()->values()->all())->toBe(['v2', config('matching.experiments.versions.3')]);
});

it('builds the funnel from the outcome event log', function () {
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);
    $employer = phase4Employer();
    $outcomes = app(MatchOutcomeService::class);
    $svc = app(MatchSnapshotService::class);

    $jobA = phase4Job($employer, ['slug' => 'a'.str()->random(6)]);
    $jobB = phase4Job($employer, ['title' => 'Platform Engineer', 'slug' => 'b'.str()->random(6)]);

    $itemA = collect(phase4RecommendationItems($candidate))->firstWhere('job.id', $jobA->id);
    $itemB = collect(phase4RecommendationItems($candidate))->firstWhere('job.id', $jobB->id);

    $outcomes->jobRecommended($candidate, $jobA, $itemA, 1);
    $outcomes->jobRecommended($candidate, $jobB, $itemB, 2);
    $outcomes->jobViewed($candidate, $jobA);
    $outcomes->jobApplied($candidate, $jobA);

    $funnel = app(MatchAnalyticsService::class)->funnel();

    expect($funnel['recommended'])->toBe(2)
        ->and($funnel['viewed'])->toBe(1)
        ->and($funnel['applied'])->toBe(1)
        ->and($funnel['view_rate'])->toBe(50.0)
        ->and($funnel['apply_rate'])->toBe(100.0);
});

it('reports rejection and hire events straight from the pipeline actions', function () {
    $employer = phase4Employer();
    $job = phase4Job($employer);
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $application = JobApplication::create([
        'job_id' => $job->id,
        'candidate_id' => $candidate->id,
        'status' => JobApplication::STATUS_APPLIED,
        'applied_at' => now(),
    ]);

    $this->actingAs($employer)
        ->post(route('employer.applications.move', ['application' => $application, 'action' => 'next']))
        ->assertRedirect();

    $this->actingAs($employer)
        ->post(route('employer.applications.move', ['application' => $application, 'action' => 'reject']))
        ->assertRedirect();

    expect(MatchOutcomeEvent::where('event_type', 'application_advanced')->count())->toBe(1)
        ->and(MatchOutcomeEvent::where('event_type', 'application_rejected')->count())->toBe(1)
        ->and(MatchOutcomeEvent::where('event_type', 'application_advanced')->first()->context['to_status'])->toBe(JobApplication::STATUS_SHORTLISTED);
});

it('records hire_recorded when an offer becomes a hire', function () {
    $employer = phase4Employer();
    $job = phase4Job($employer);
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $application = JobApplication::create([
        'job_id' => $job->id,
        'candidate_id' => $candidate->id,
        'status' => JobApplication::STATUS_OFFER,
        'applied_at' => now(),
    ]);

    $this->actingAs($employer)
        ->post(route('employer.applications.move', ['application' => $application, 'action' => 'next']))
        ->assertRedirect();

    expect(MatchOutcomeEvent::where('event_type', 'hire_recorded')->count())->toBe(1)
        ->and($application->refresh()->status)->toBe(JobApplication::STATUS_HIRED);
});

it('records interview_scheduled when an interview is scheduled from an application', function () {
    $employer = phase4Employer();
    $job = phase4Job($employer);
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel', 'MySQL', 'Docker']);
    $application = JobApplication::create([
        'job_id' => $job->id,
        'candidate_id' => $candidate->id,
        'status' => JobApplication::STATUS_SHORTLISTED,
        'applied_at' => now(),
    ]);

    $this->actingAs($employer)
        ->post(route('employer.applications.schedule-interview.store', ['application' => $application, 'scheduled_date' => today()->toDateString(), 'scheduled_time' => '10:00', 'interview_type' => 'video']))
        ->assertRedirect();

    expect(MatchOutcomeEvent::where('event_type', 'interview_scheduled')->count())->toBe(1)
        ->and(MatchOutcomeEvent::where('event_type', 'interview_scheduled')->first()->candidate_id)->toBe($candidate->id);
});

it('logs a shortlist event with a snapshot when the employer shortlists a candidate', function () {
    $employer = phase4Employer();
    $job = phase4Job($employer);
    $candidate = phase4Candidate(skills: ['PHP', 'Laravel']);

    $this->actingAs($employer)
        ->post(route('employer.marketplace.shortlist', ['candidate' => $candidate, 'job_id' => $job->id]))
        ->assertRedirect();

    $event = MatchOutcomeEvent::where('event_type', 'candidate_shortlisted')->first();

    expect($event)->not->toBeNull()
        ->and($event->employer_id)->toBe($employer->id)
        ->and($event->snapshot_id)->not->toBeNull()
        ->and(EmployerShortlist::where('employer_id', $employer->id)->where('candidate_id', $candidate->id)->exists())->toBeTrue();
});

it('logs one candidate_contacted_employer event per new conversation', function () {
    $employer = phase4Employer();
    $candidate = phase4Candidate();
    $job = phase4Job($employer);

    foreach ([1, 2, 3] as $i) {
        $this->actingAs($candidate)
            ->postJson(route('candidate.messages.create', ['employer' => $employer, 'job_id' => $job->id]))
            ->assertOk();
    }

    expect(MatchOutcomeEvent::where('event_type', 'candidate_contacted_employer')->count())->toBe(1)
        ->and(Conversation::count())->toBe(1);
});

it('role-gates candidate feedback and upserts a single row per job', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());

    $this->actingAs(phase4Employer())
        ->post(route('candidate.feedback.store', ['job_id' => $job->id, 'feedback' => 'not_relevant']))
        ->assertForbidden();

    $this->actingAs($candidate)
        ->from('/candidate/recommended-jobs')
        ->post(route('candidate.feedback.store', ['job_id' => $job->id, 'feedback' => 'not_relevant']))
        ->assertRedirect();

    $this->actingAs($candidate)
        ->post(route('candidate.feedback.store', ['job_id' => $job->id, 'feedback' => 'wrong_role']))
        ->assertRedirect();

    expect(CandidateRecommendationFeedback::count())->toBe(1)
        ->and(CandidateRecommendationFeedback::first()->feedback_type)->toBe('wrong_role')
        ->and(CandidateRecommendationFeedback::first()->is_relevant)->toBeFalse();
});

it('role-gates employer feedback and keeps one row per candidate', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());
    $employer = phase4Employer();

    $this->actingAs($candidate)
        ->post(route('employer.feedback.store', ['candidate' => $candidate, 'feedback' => 'skills_mismatch']))
        ->assertForbidden();

    $this->actingAs($employer)
        ->post(route('employer.feedback.store', ['candidate' => $candidate, 'job_id' => $job->id, 'feedback' => 'relevant_candidate']))
        ->assertRedirect();

    $this->actingAs($employer)
        ->post(route('employer.feedback.store', ['candidate' => $candidate, 'feedback' => 'not_interested']))
        ->assertRedirect();

    $rows = EmployerRecommendationFeedback::get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('feedback_key')->all())->toContain(
            "e:{$employer->id}:{$candidate->id}:{$job->id}",
            "e:{$employer->id}:{$candidate->id}:none",
        );
});

it('rejects feedback types that are not part of the configured set', function () {
    $candidate = phase4Candidate();
    $job = phase4Job(phase4Employer());

    $this->actingAs($candidate)
        ->post(route('candidate.feedback.store', ['job_id' => $job->id, 'feedback' => 'made_up_type']))
        ->assertStatus(422);
});
