<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateCandidateMatches;
use App\Models\JobRole;
use App\Models\PersonalityQuestion;
use App\Models\User;
use App\Services\PersonalityAssessmentService;
use App\Services\ProfileCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CandidateOnboardingController extends Controller
{
    public const TOTAL_STEPS = 8;

    private const STEP_CATEGORIES = [
        4 => [
            'work_style',
            'communication_style',
            'team_dynamics',
            'problem_solving',
        ],
        5 => [
            'leadership_initiative',
            'work_environment_preference',
            'motivation_drivers',
            'temperament_indicators',
        ],
    ];

    public function __construct(
        protected PersonalityAssessmentService $assessmentService
    ) {}

    public function show(Request $request)
    {
        $user = Auth::user();

        if ($this->blocksOnboardingReentry($user)) {
            return redirect()->route('candidate.dashboard');
        }

        $step = $request->route('step') ?? 1;
        $step = max(1, min(self::TOTAL_STEPS, (int) $step));

        if (isset(self::STEP_CATEGORIES[$step])) {
            return redirect()->route('candidate.onboarding.assessment', ['step' => $step]);
        }

        return view("onboarding.steps.{$step}", [
            'step' => $step,
            'totalSteps' => self::TOTAL_STEPS,
            'user' => $user,
        ]);
    }

    public function showAssessment(Request $request, int $step)
    {
        $user = Auth::user();

        if ($this->blocksOnboardingReentry($user)) {
            return redirect()->route('candidate.dashboard');
        }

        $categories = $this->categoriesForStep($step);

        $firstQuestion = $this->assessmentService->getFirstQuestionForSections($categories);

        if (! $firstQuestion) {
            return redirect()->route('candidate.onboarding.step', ['step' => min(self::TOTAL_STEPS, $step + 1)]);
        }

        return redirect()->route('candidate.onboarding.assessment.question', [
            'step' => $step,
            'question' => $firstQuestion,
        ]);
    }

    public function showAssessmentQuestion(Request $request, int $step, PersonalityQuestion $question)
    {
        $user = Auth::user();

        $categories = $this->categoriesForStep($step);

        if (! in_array($question->category, $categories, true) || ! $question->is_active) {
            return redirect()->route('candidate.onboarding.assessment', ['step' => $step]);
        }

        $question->load('options');

        $totalQuestions = $this->assessmentService->getTotalQuestionsForSections($categories);
        $currentQuestionNumber = $this->assessmentService->getQuestionNumberForSections($categories, $question);
        $previousQuestion = $this->assessmentService->getPreviousQuestionForSections($categories, $question);
        $existingAnswer = $user->personalityAnswers()
            ->where('question_id', $question->id)
            ->first();

        return view('onboarding.assessment.question', [
            'step' => $step,
            'totalSteps' => self::TOTAL_STEPS,
            'title' => 'Personality Assessment',
            'user' => $user,
            'question' => $question,
            'totalQuestions' => $totalQuestions,
            'currentQuestionNumber' => $currentQuestionNumber,
            'previousQuestion' => $previousQuestion,
            'existingAnswer' => $existingAnswer,
        ]);
    }

    public function saveAssessmentAnswer(Request $request, int $step, PersonalityQuestion $question)
    {
        $user = Auth::user();

        $categories = $this->categoriesForStep($step);

        if (! in_array($question->category, $categories, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'option_id' => 'required|exists:personality_question_options,id',
        ]);

        $option = $question->options()->findOrFail($validated['option_id']);

        $this->assessmentService->saveAnswer($user, $question, $option->id);

        $this->updateProfileCompletion($user);

        $nextQuestion = $this->assessmentService->getNextQuestionForSections($categories, $question);

        if ($nextQuestion) {
            return redirect()->route('candidate.onboarding.assessment.question', [
                'step' => $step,
                'question' => $nextQuestion,
            ]);
        }

        if ($step === 4) {
            return redirect()->route('candidate.onboarding.assessment', ['step' => 5])
                ->with('success', 'Part one complete. Let\'s finish the second part!');
        }

        $this->assessmentService->completeAssessment($user);
        $this->syncTemperamentFromProfile($user);

        return redirect()->route('candidate.onboarding.step', ['step' => 6])
            ->with('success', 'Assessment complete! Your profile has been updated.');
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $step = (int) $request->route('step');

        $this->validateStep($request, $step);

        $this->saveStep($request, $user, $step);

        $this->updateProfileCompletion($user);

        if ($step < self::TOTAL_STEPS) {
            return redirect()->route('candidate.onboarding.step', ['step' => $step + 1])
                ->with('success', 'Progress saved!');
        }

        return $this->completeOnboarding($user);
    }

    protected function validateStep(Request $request, int $step): void
    {
        $rules = match ($step) {
            1 => [
                'current_role' => 'nullable|string|max:255',
                'desired_role' => 'nullable|string|max:255',
                'desired_category_id' => 'nullable|exists:job_categories,id',
                'desired_role_id' => [
                    'nullable',
                    'exists:job_roles,id',
                    function ($attribute, $value, $fail) {
                        if ($value === null) {
                            return;
                        }

                        $role = JobRole::find($value);

                        if ($role === null) {
                            return;
                        }

                        if (
                            request()->input('desired_category_id') !== null
                            && (int) request()->input('desired_category_id') !== (int) $role->category_id
                        ) {
                            $fail('The selected desired role does not belong to the selected category.');
                        }
                    },
                ],
                'years_of_experience' => 'nullable|integer|min:0|max:50',
                'industry' => 'nullable|string|max:255',
                'employment_type_preference' => ['nullable', Rule::in(['full-time', 'part-time', 'contract', 'freelance'])],
                'work_preference' => ['nullable', Rule::in(['remote', 'hybrid', 'onsite'])],
            ],
            2 => [
                'skills' => 'nullable|array',
                'skills.*.name' => 'nullable|string|max:255',
                'skills.*.level' => 'nullable|integer|min:1|max:5',
                'experiences' => 'nullable|array',
                'experiences.*.company' => 'nullable|string|max:255',
                'experiences.*.role' => 'nullable|string|max:255',
                'experiences.*.duration' => 'nullable|string|max:100',
                'experiences.*.description' => 'nullable|string',
                'education' => 'nullable|array',
                'education.*.institution' => 'nullable|string|max:255',
                'education.*.qualification' => 'nullable|string|max:255',
                'education.*.year_completed' => 'nullable|integer|min:1950|max:2030',
            ],
            3 => [
                'skill_score' => 'nullable|integer|min:0|max:100',
                'subskill_breakdown' => 'nullable|array',
            ],
            6 => [
                'organizational_type' => 'nullable|string|max:255',
                'motivation_drivers' => 'nullable|array',
            ],
            7 => [
                'greatest_achievement' => 'nullable|string',
            ],
            8 => [
                'role_video_url' => 'nullable|string|max:500',
                'cv_path' => 'required|file|mimes:pdf,doc,docx|max:2048',
                'portfolio_url' => 'nullable|string|max:500',
                'linkedin_url' => 'nullable|string|max:500',
                'github_url' => 'nullable|string|max:500',
                'portfolio_links' => 'nullable|array',
            ],
            default => [],
        };

        $request->validate($rules);
    }

    protected function saveStep(Request $request, $user, int $step): void
    {
        match ($step) {
            1 => $this->saveStep1($user, $request),
            2 => $this->saveStep2($user, $request),
            3 => $this->saveStep3($user, $request),
            6 => $this->saveStep6($user, $request),
            7 => $this->saveStep7($user, $request),
            8 => $this->saveStep8($user, $request),
            default => null,
        };
    }

    protected function saveStep1($user, Request $request): void
    {
        $profile = $user->candidateProfile()->firstOrNew([]);
        $profile->fill($request->only([
            'current_role',
            'desired_role',
            'desired_category_id',
            'desired_role_id',
            'years_of_experience',
            'industry',
            'employment_type_preference',
            'work_preference',
        ]));
        $profile->save();
    }

    protected function saveStep2($user, Request $request): void
    {
        $user->candidateSkills()->delete();
        if ($request->has('skills')) {
            foreach ($request->input('skills') as $skill) {
                if (! empty($skill['name'])) {
                    $user->candidateSkills()->create([
                        'skill_name' => $skill['name'],
                        'proficiency_level' => $skill['level'] ?? 1,
                    ]);
                }
            }
        }

        $user->candidateExperiences()->delete();
        if ($request->has('experiences')) {
            foreach ($request->input('experiences') as $exp) {
                if (! empty($exp['company'])) {
                    $user->candidateExperiences()->create([
                        'company' => $exp['company'],
                        'role' => $exp['role'] ?? '',
                        'duration' => $exp['duration'] ?? '',
                        'description' => $exp['description'] ?? '',
                    ]);
                }
            }
        }

        $user->candidateEducation()->delete();
        if ($request->has('education')) {
            foreach ($request->input('education') as $edu) {
                if (! empty($edu['institution'])) {
                    $user->candidateEducation()->create([
                        'institution' => $edu['institution'],
                        'qualification' => $edu['qualification'] ?? '',
                        'year_completed' => $edu['year_completed'] ?? date('Y'),
                    ]);
                }
            }
        }
    }

    protected function saveStep3($user, Request $request): void
    {
        $assessment = $user->candidateAssessment()->firstOrNew([]);
        $assessment->fill([
            'skill_score' => $request->input('skill_score', 0),
            'subskill_breakdown_json' => $request->input('subskill_breakdown', []),
        ]);
        $assessment->save();
    }

    protected function saveStep6($user, Request $request): void
    {
        $preferences = $user->candidatePreferences()->firstOrNew([]);
        $preferences->fill([
            'organizational_type' => $request->input('organizational_type'),
            'motivation_drivers_json' => $request->input('motivation_drivers', []),
        ]);
        $preferences->save();
    }

    protected function saveStep7($user, Request $request): void
    {
        $profile = $user->candidateProfile()->firstOrNew([]);
        $profile->fill([
            'greatest_achievement' => $request->input('greatest_achievement'),
        ]);
        $profile->save();
    }

    protected function saveStep8($user, Request $request): void
    {
        $media = $user->candidateMedia()->firstOrNew([]);

        $portfolio = $request->input('portfolio_url');
        $portfolioLinks = $portfolio ? [$portfolio] : [];

        $media->fill([
            'role_video_url' => $request->input('role_video_url'),
            'linkedin_url' => $request->input('linkedin_url'),
            'github_url' => $request->input('github_url'),
            'portfolio_links_json' => $portfolioLinks,
        ]);

        if ($request->hasFile('cv_path')) {
            $media->cv_path = $request->file('cv_path')->store('cvs/'.$user->id, 'public');
        }

        $media->save();
    }

    protected function updateProfileCompletion($user): void
    {
        app(ProfileCompletionService::class)->sync($user);
    }

    protected function canCompleteOnboarding($user): bool
    {
        if (! $user->candidateProfile || ! $user->candidateProfile->desired_role) {
            return false;
        }

        if (! $user->candidateAssessment) {
            return false;
        }

        return true;
    }

    protected function completeOnboarding($user): RedirectResponse
    {
        if (! $this->canCompleteOnboarding($user)) {
            return redirect()->route('candidate.onboarding.step', ['step' => 1])
                ->with('error', 'Please complete all required sections before finishing onboarding.');
        }

        $user->onboarding_completed = true;
        $user->save();

        app(ProfileCompletionService::class)->sync($user);

        RecalculateCandidateMatches::dispatch($user);

        return redirect()->route('candidate.dashboard')
            ->with('success', 'Onboarding completed! Welcome to Gridspace.');
    }

    public function skip(Request $request)
    {
        $user = Auth::user();

        if (! $user->candidateProfile) {
            $user->candidateProfile()->create([]);
        }
        if (! $user->candidateMedia) {
            $user->candidateMedia()->create([
                'role_video_url' => '',
                'cv_path' => '',
            ]);
        }
        if (! $user->candidateAssessment) {
            $user->candidateAssessment()->create([]);
        }
        if (! $user->candidatePreferences) {
            $user->candidatePreferences()->create([]);
        }

        app(ProfileCompletionService::class)->sync($user);

        $user->onboarding_completed = true;
        $user->save();

        RecalculateCandidateMatches::dispatch($user);

        return redirect()->route('candidate.dashboard');
    }

    private function categoriesForStep(int $step): array
    {
        return self::STEP_CATEGORIES[$step] ?? [];
    }

    private function blocksOnboardingReentry(User $user): bool
    {
        if (! $user->onboarding_completed) {
            return false;
        }

        return app(ProfileCompletionService::class)->complete($user);
    }

    private function syncTemperamentFromProfile($user): void
    {
        $profile = $user->personalityProfile;

        if (! $profile || ! $profile->temperament_type) {
            return;
        }

        $discMap = [
            'Decisive' => 'driver',
            'Analytical' => 'analytical',
            'Energetic' => 'expressive',
            'Calm' => 'amiable',
        ];

        $assessment = $user->candidateAssessment()->firstOrNew([]);
        $assessment->temperament_type = $discMap[$profile->temperament_type] ?? $profile->temperament_type;
        $assessment->save();
    }
}
