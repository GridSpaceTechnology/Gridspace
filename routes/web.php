<?php

use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminCandidateController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEmployerController;
use App\Http\Controllers\Admin\AdminJobController;
use App\Http\Controllers\Admin\AdminMatchingAnalyticsController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminVerificationController;
use App\Http\Controllers\Admin\PersonalityAnalyticsController;
use App\Http\Controllers\Admin\PersonalityProfileController;
use App\Http\Controllers\Admin\PersonalityQuestionController;
use App\Http\Controllers\Candidate\CandidateProfileEditController;
use App\Http\Controllers\Candidate\CandidateVerificationController;
use App\Http\Controllers\CandidateDashboardController;
use App\Http\Controllers\CandidateOnboardingController;
use App\Http\Controllers\CandidatePortfolioController;
use App\Http\Controllers\CandidateProfileController;
use App\Http\Controllers\CandidateRecommendationController;
use App\Http\Controllers\CandidateResumeController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\Employer\ATS\ApplicationNoteController;
use App\Http\Controllers\Employer\ATS\ATSController;
use App\Http\Controllers\Employer\ATS\CandidateRatingController;
use App\Http\Controllers\Employer\EmployerOnboardingCultureController;
use App\Http\Controllers\Employer\EmployerProfileController;
use App\Http\Controllers\Employer\EmployerSetupController;
use App\Http\Controllers\EmployerCultureController;
use App\Http\Controllers\EmployerDashboardController;
use App\Http\Controllers\EmployerInterviewController;
use App\Http\Controllers\EmployerJobCandidateController;
use App\Http\Controllers\EmployerJobController;
use App\Http\Controllers\EmployerMarketplaceController;
use App\Http\Controllers\EmployerPipelineController;
use App\Http\Controllers\InterviewMessageController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PersonalityAssessmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicJobController;
use App\Http\Controllers\PublicMarketplaceController;
use App\Http\Controllers\RecommendationFeedbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/jobs', [PublicJobController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{job}', [PublicJobController::class, 'show'])->name('jobs.show');

Route::get('/marketplace', [PublicMarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('/marketplace/candidates/{candidate}', [PublicMarketplaceController::class, 'showCandidate'])->name('marketplace.candidate');

Route::get('/dashboard', function () {
    $user = auth()->user();
    if ($user->role === 'admin') {
        return redirect()->route('admin.dashboard');
    }
    if ($user->role === 'candidate') {
        return redirect()->route('candidate.dashboard');
    }

    return redirect()->route('employer.dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');

    Route::post('/welcome/dismiss', function () {
        auth()->user()->dismissWelcome();

        return response()->json(['success' => true]);
    })->name('welcome.dismiss');
});

Route::get('/candidates/{candidate}', [CandidatePortfolioController::class, 'show'])
    ->name('candidates.show');

Route::get('/employers/{company}', [CompanyProfileController::class, 'show'])
    ->name('employers.show');

require __DIR__.'/auth.php';

Route::middleware(['auth', 'role:candidate'])->group(function () {
    Route::get('/candidate/onboarding', [CandidateOnboardingController::class, 'show'])
        ->name('candidate.onboarding');

    Route::get('/candidate/onboarding/step/{step}', [CandidateOnboardingController::class, 'show'])
        ->name('candidate.onboarding.step');

    Route::post('/candidate/onboarding/step/{step}', [CandidateOnboardingController::class, 'store'])
        ->name('candidate.onboarding.store');

    Route::get('/candidate/onboarding/assessment/{step}', [CandidateOnboardingController::class, 'showAssessment'])
        ->name('candidate.onboarding.assessment');

    Route::get('/candidate/onboarding/assessment/{step}/{question}', [CandidateOnboardingController::class, 'showAssessmentQuestion'])
        ->name('candidate.onboarding.assessment.question');

    Route::post('/candidate/onboarding/assessment/{step}/{question}/answer', [CandidateOnboardingController::class, 'saveAssessmentAnswer'])
        ->name('candidate.onboarding.assessment.answer');

    Route::post('/candidate/onboarding/skip', [CandidateOnboardingController::class, 'skip'])
        ->name('candidate.onboarding.skip');

    Route::get('/candidate/dashboard', [CandidateDashboardController::class, 'index'])
        ->name('candidate.dashboard');

    Route::get('/candidate/jobs', [CandidateDashboardController::class, 'jobs'])
        ->name('candidate.jobs');

    Route::post('/candidate/jobs/{job}/apply', [CandidateDashboardController::class, 'apply'])
        ->name('candidate.jobs.apply');

    Route::get('/candidate/applications', [CandidateDashboardController::class, 'applications'])
        ->name('candidate.applications');

    Route::get('/candidate/interviews', [CandidateDashboardController::class, 'interviews'])
        ->name('candidate.interviews');

    Route::get('/candidate/profile/{user}', [CandidateProfileController::class, 'show'])
        ->name('candidate.profile.show');

    Route::get('/candidate/profile', [CandidateProfileEditController::class, 'edit'])
        ->name('candidate.profile.edit');
    Route::patch('/candidate/profile', [CandidateProfileEditController::class, 'update'])
        ->name('candidate.profile.update');

    Route::get('/candidate/resume/download', [CandidateResumeController::class, 'download'])
        ->name('candidate.resume.download');
    Route::get('/candidate/resume/view', [CandidateResumeController::class, 'show'])
        ->name('candidate.resume.view');

    Route::get('/candidate/recommended-jobs', [CandidateRecommendationController::class, 'index'])
        ->name('candidate.recommended-jobs');

    Route::post('/candidate/recommended-jobs/feedback', [RecommendationFeedbackController::class, 'candidate'])
        ->middleware('throttle:30,1')
        ->name('candidate.feedback.store');

    Route::get('/candidate/personality-assessment', [PersonalityAssessmentController::class, 'start'])
        ->name('candidate.personality.start');

    Route::get('/candidate/personality-assessment/complete', [PersonalityAssessmentController::class, 'complete'])
        ->name('candidate.personality.complete');

    Route::post('/candidate/personality-assessment/skip', [PersonalityAssessmentController::class, 'skip'])
        ->name('candidate.personality.skip');

    Route::get('/candidate/personality-assessment/{question}', [PersonalityAssessmentController::class, 'showQuestion'])
        ->name('candidate.personality.question');

    Route::post('/candidate/personality-assessment/{question}/answer', [PersonalityAssessmentController::class, 'answer'])
        ->name('candidate.personality.answer');

    Route::get('/candidate/verification', [CandidateVerificationController::class, 'index'])
        ->name('candidate.verification');

    Route::post('/candidate/verification/{verification_type}/submit', [CandidateVerificationController::class, 'submit'])
        ->name('candidate.verification.submit');

    Route::post('/candidate/verification/phone', [CandidateVerificationController::class, 'verifyPhone'])
        ->name('candidate.verification.phone');

    Route::post('/candidate/verification/phone/otp', [CandidateVerificationController::class, 'verifyPhoneOtp'])
        ->name('candidate.verification.phone.otp');

    Route::get('/messages', [MessageController::class, 'index'])
        ->name('messages.index');

    Route::get('/messages/{conversation}', [MessageController::class, 'show'])
        ->name('messages.show');

    Route::post('/messages/{conversation}/send', [MessageController::class, 'sendMessage'])
        ->name('messages.send');

    Route::post('/candidate/messages/conversation/{employer}', [MessageController::class, 'createOrGetConversation'])
        ->middleware('role:candidate')
        ->name('candidate.messages.create');

    Route::post('/messages/{conversation}/typing', [MessageController::class, 'typing'])
        ->name('messages.typing');

    Route::get('/messages/search', [MessageController::class, 'search'])
        ->name('messages.search');

    Route::post('/messages/interview/{interview}/respond', [InterviewMessageController::class, 'respondToInvitation'])
        ->name('messages.interview.respond');
});

Route::middleware(['auth', 'role:employer'])->group(function () {
    Route::get('/employer/onboarding', function () {
        $user = auth()->user();
        if ($user->onboarding_completed) {
            return redirect()->route('employer.dashboard');
        }

        return redirect()->route('employer.setup');
    })->name('employer.onboarding');

    Route::get('/employer/setup', [EmployerSetupController::class, 'show'])
        ->name('employer.setup');
    Route::post('/employer/setup', [EmployerSetupController::class, 'store']);

    Route::get('/employer/onboarding/culture', [EmployerOnboardingCultureController::class, 'start'])
        ->name('employer.onboarding.culture');

    Route::get('/employer/onboarding/culture/{question}', [EmployerOnboardingCultureController::class, 'showQuestion'])
        ->name('employer.onboarding.culture.question');

    Route::post('/employer/onboarding/culture/{question}/answer', [EmployerOnboardingCultureController::class, 'answer'])
        ->name('employer.onboarding.culture.answer');

    Route::get('/employer/dashboard', [EmployerDashboardController::class, 'index'])
        ->name('employer.dashboard');

    Route::get('/employer/profile', [EmployerProfileController::class, 'edit'])
        ->name('employer.profile.edit');
    Route::patch('/employer/profile', [EmployerProfileController::class, 'update'])
        ->name('employer.profile.update');

    Route::get('/employer/jobs', [EmployerJobController::class, 'index'])
        ->name('employer.jobs.index');

    Route::get('/employer/jobs/create', [EmployerJobController::class, 'create'])
        ->name('employer.jobs.create');

    Route::post('/employer/jobs', [EmployerJobController::class, 'store'])
        ->name('employer.jobs.store');

    Route::get('/employer/jobs/{job}', [EmployerJobController::class, 'show'])
        ->name('employer.jobs.show');

    Route::get('/employer/jobs/{job}/edit', [EmployerJobController::class, 'edit'])
        ->name('employer.jobs.edit');

    Route::patch('/employer/jobs/{job}', [EmployerJobController::class, 'update'])
        ->name('employer.jobs.update');

    Route::delete('/employer/jobs/{job}', [EmployerJobController::class, 'destroy'])
        ->name('employer.jobs.destroy');

    Route::get('/employer/jobs/{job}/candidates', [EmployerJobCandidateController::class, 'index'])
        ->name('employer.jobs.candidates');

    Route::get('/employer/jobs/{job}/pipeline', [EmployerPipelineController::class, 'index'])
        ->name('employer.jobs.pipeline');

    Route::post('/employer/applications/{application}/move', [EmployerPipelineController::class, 'moveStage'])
        ->name('employer.applications.move');

    Route::get('/employer/applications/{application}/schedule-interview', [EmployerInterviewController::class, 'scheduleFromApplication'])
        ->name('employer.applications.schedule-interview');

    Route::post('/employer/applications/{application}/schedule-interview', [EmployerInterviewController::class, 'storeFromApplication'])
        ->name('employer.applications.schedule-interview.store');

    Route::get('/employer/marketplace', [EmployerMarketplaceController::class, 'index'])
        ->name('employer.marketplace.index');

    Route::get('/employer/marketplace/candidates', [EmployerMarketplaceController::class, 'candidates'])
        ->name('employer.marketplace.candidates');

    Route::post('/employer/marketplace/candidates/{candidate}/shortlist', [EmployerMarketplaceController::class, 'shortlist'])
        ->name('employer.marketplace.shortlist');

    Route::post('/employer/marketplace/candidates/{candidate}/feedback', [RecommendationFeedbackController::class, 'employer'])
        ->name('employer.feedback.store');

    Route::get('/employer/marketplace/candidates/{candidate}', [EmployerMarketplaceController::class, 'showCandidate'])
        ->name('employer.marketplace.candidate');

    Route::get('/employer/marketplace/candidates/{candidate}/resume/download', [CandidateResumeController::class, 'download'])
        ->name('employer.resume.download');
    Route::get('/employer/marketplace/candidates/{candidate}/resume/view', [CandidateResumeController::class, 'show'])
        ->name('employer.resume.view');

    Route::get('/employer/shortlists', [EmployerMarketplaceController::class, 'shortlists'])
        ->name('employer.shortlists');

    Route::get('/employer/interviews', [EmployerInterviewController::class, 'index'])
        ->name('employer.interviews.index');

    Route::get('/employer/interviews/create', [EmployerInterviewController::class, 'create'])
        ->name('employer.interviews.create');

    Route::post('/employer/interviews', [EmployerInterviewController::class, 'store'])
        ->name('employer.interviews.store');

    Route::get('/employer/interviews/{interview}', [EmployerInterviewController::class, 'show'])
        ->name('employer.interviews.show');

    Route::post('/employer/interviews/{interview}/cancel', [EmployerInterviewController::class, 'cancel'])
        ->name('employer.interviews.cancel');

    Route::post('/employer/interviews/{interview}/complete', [EmployerInterviewController::class, 'complete'])
        ->name('employer.interviews.complete');

    Route::get('/employer/messages', [MessageController::class, 'index'])
        ->name('employer.messages');

    Route::post('/employer/messages/conversation/{candidate}', [MessageController::class, 'createOrGetConversation'])
        ->name('employer.messages.create');

    Route::get('/employer/messages/{conversation}', [MessageController::class, 'show'])
        ->name('employer.messages.show');

    Route::get('/employer/messages/{conversation}/messages', [MessageController::class, 'getMessages'])
        ->name('employer.messages.get');

    Route::post('/employer/messages/{conversation}/send', [MessageController::class, 'sendMessage'])
        ->name('employer.messages.send');

    Route::post('/employer/messages/{conversation}/read', [MessageController::class, 'markAsRead'])
        ->name('employer.messages.read');

    Route::post('/employer/messages/{conversation}/typing', [MessageController::class, 'typing'])
        ->name('employer.messages.typing');

    Route::get('/employer/messages/search', [MessageController::class, 'search'])
        ->name('employer.messages.search');

    Route::post('/employer/messages/{conversation}/interview', [InterviewMessageController::class, 'sendInvitation'])
        ->name('employer.messages.interview.send');

    Route::post('/employer/messages/{conversation}/decision', [InterviewMessageController::class, 'sendDecision'])
        ->name('employer.messages.decision.send');

    Route::get('/employer/ats', [ATSController::class, 'dashboard'])
        ->name('employer.ats.dashboard');

    Route::get('/employer/ats/applications/{application}', [ATSController::class, 'show'])
        ->name('employer.ats.show');

    Route::get('/employer/ats/analytics', [ATSController::class, 'analytics'])
        ->name('employer.ats.analytics');

    Route::post('/employer/ats/applications/{application}/notes', [ApplicationNoteController::class, 'store'])
        ->name('employer.ats.notes.store');

    Route::delete('/employer/ats/notes/{note}', [ApplicationNoteController::class, 'destroy'])
        ->name('employer.ats.notes.destroy');

    Route::post('/employer/ats/applications/{application}/ratings', [CandidateRatingController::class, 'store'])
        ->name('employer.ats.ratings.store');

    Route::get('/employer/culture-assessment', [EmployerCultureController::class, 'show'])
        ->name('employer.culture.assessment');

    Route::post('/employer/culture-assessment', [EmployerCultureController::class, 'store'])
        ->name('employer.culture.store');
});

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('admin.dashboard'))->name('admin');
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

        Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        Route::patch('/users/{user}/verify', [AdminUserController::class, 'verify'])->name('admin.users.verify');
        Route::get('/candidates', [AdminCandidateController::class, 'index'])->name('admin.candidates');
        Route::get('/employers', [AdminEmployerController::class, 'index'])->name('admin.employers');
        Route::patch('/employers/{employer}/verify', [AdminEmployerController::class, 'verify'])->name('admin.employers.verify');

        Route::get('/jobs', [AdminJobController::class, 'index'])->name('admin.jobs');
        Route::get('/jobs/{job}/edit', [AdminJobController::class, 'edit'])->name('admin.jobs.edit');
        Route::patch('/jobs/{job}', [AdminJobController::class, 'update'])->name('admin.jobs.update');
        Route::delete('/jobs/{job}', [AdminJobController::class, 'destroy'])->name('admin.jobs.destroy');
        Route::post('/jobs/{job}/toggle-status', [AdminJobController::class, 'toggleStatus'])->name('admin.jobs.toggle-status');
        Route::get('/jobs/{job}', [AdminJobController::class, 'show'])->name('admin.jobs.show');

        Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('admin.analytics');

        Route::get('/matching-analytics', [AdminMatchingAnalyticsController::class, 'index'])
            ->name('admin.matching.analytics');

        Route::get('/verifications', [AdminVerificationController::class, 'index'])->name('admin.verifications.index');
        Route::get('/verifications/pending', [AdminVerificationController::class, 'pending'])->name('admin.verifications.pending');
        Route::get('/verifications/{verification}', [AdminVerificationController::class, 'show'])->name('admin.verifications.show');
        Route::post('/verifications/{verification}/approve', [AdminVerificationController::class, 'approve'])->name('admin.verifications.approve');
        Route::post('/verifications/{verification}/reject', [AdminVerificationController::class, 'reject'])->name('admin.verifications.reject');
        Route::post('/verifications/{verification}/request-info', [AdminVerificationController::class, 'requestInfo'])->name('admin.verifications.request-info');
        Route::get('/verifications/stats/overview', [AdminVerificationController::class, 'stats'])->name('admin.verifications.stats');

        Route::get('/messages', [AdminMessageController::class, 'index'])->name('admin.messages.index');
        Route::get('/messages/{conversation}', [AdminMessageController::class, 'show'])->name('admin.messages.show');
        Route::get('/messages/stats/overview', [AdminMessageController::class, 'stats'])->name('admin.messages.stats');
        Route::delete('/messages/{conversation}', [AdminMessageController::class, 'destroy'])->name('admin.messages.destroy');

        Route::prefix('personality')->group(function () {
            Route::get('/questions', [PersonalityQuestionController::class, 'index'])
                ->name('admin.personality.questions.index');

            Route::get('/questions/create', [PersonalityQuestionController::class, 'create'])
                ->name('admin.personality.questions.create');

            Route::post('/questions', [PersonalityQuestionController::class, 'store'])
                ->name('admin.personality.questions.store');

            Route::get('/questions/{question}/edit', [PersonalityQuestionController::class, 'edit'])
                ->name('admin.personality.questions.edit');

            Route::patch('/questions/{question}', [PersonalityQuestionController::class, 'update'])
                ->name('admin.personality.questions.update');

            Route::delete('/questions/{question}', [PersonalityQuestionController::class, 'destroy'])
                ->name('admin.personality.questions.destroy');

            Route::post('/questions/{question}/toggle-status', [PersonalityQuestionController::class, 'toggleStatus'])
                ->name('admin.personality.questions.toggle');

            Route::post('/questions/reorder', [PersonalityQuestionController::class, 'reorder'])
                ->name('admin.personality.questions.reorder');

            Route::get('/analytics', [PersonalityAnalyticsController::class, 'index'])
                ->name('admin.personality.analytics');

            Route::get('/profiles', [PersonalityProfileController::class, 'index'])
                ->name('admin.personality.profiles.candidates');

            Route::get('/profiles/employers', [PersonalityProfileController::class, 'employerProfiles'])
                ->name('admin.personality.profiles.employers');
        });
    });
