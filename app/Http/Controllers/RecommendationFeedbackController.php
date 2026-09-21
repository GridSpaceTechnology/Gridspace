<?php

namespace App\Http\Controllers;

use App\Enums\CandidateRecommendationFeedbackType;
use App\Enums\EmployerRecommendationFeedbackType;
use App\Models\CandidateRecommendationFeedback;
use App\Models\EmployerRecommendationFeedback;
use App\Models\Job;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Explicit recommendation feedback.
 *
 * Feedback is stored on a unique per-target key (one assessment per candidate
 * / job / employer context) and drives internal analytics and the bounded
 * negative signal. It never mutates match scores directly.
 */
class RecommendationFeedbackController extends Controller
{
    public function candidate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'job_id' => 'required|exists:job_listings,id',
            'feedback' => 'required|string|max:30',
        ]);

        $type = CandidateRecommendationFeedbackType::tryFrom($validated['feedback']);

        abort_unless($type && array_key_exists($type->value, config('matching.feedback.candidate', [])), 422);

        $job = Job::findOrFail($validated['job_id']);
        $user = $request->user();

        $this->storeCandidateFeedback($user->id, $job->id, $type);

        return back()->with('success', 'Thanks! We will refine future recommendations.');
    }

    public function employer(Request $request, User $candidate): RedirectResponse
    {
        $validated = $request->validate([
            'job_id' => 'nullable|exists:job_listings,id',
            'feedback' => 'required|string|max:30',
        ]);

        $type = EmployerRecommendationFeedbackType::tryFrom($validated['feedback']);

        abort_unless($type && array_key_exists($type->value, config('matching.feedback.employer', [])), 422);

        $this->storeEmployerFeedback($request->user()->id, $candidate->id, $validated['job_id'] ?? null, $type);

        return back()->with('success', 'Feedback recorded. Thank you!');
    }

    protected function storeCandidateFeedback(int $candidateId, int $jobId, CandidateRecommendationFeedbackType $type): void
    {
        $feedback = CandidateRecommendationFeedback::firstWhere('feedback_key', "c:{$candidateId}:{$jobId}")
            ?? new CandidateRecommendationFeedback;

        $feedback->forceFill([
            'candidate_id' => $candidateId,
            'job_id' => $jobId,
            'feedback_type' => $type->value,
            'is_relevant' => ! $type->isNegativeSignal(),
            'feedback_key' => "c:{$candidateId}:{$jobId}",
        ])->save();
    }

    protected function storeEmployerFeedback(int $employerId, int $candidateId, ?int $jobId, EmployerRecommendationFeedbackType $type): void
    {
        $key = "e:{$employerId}:{$candidateId}:".($jobId ?? 'none');

        $feedback = EmployerRecommendationFeedback::firstWhere('feedback_key', $key)
            ?? new EmployerRecommendationFeedback;

        $feedback->forceFill([
            'employer_id' => $employerId,
            'candidate_id' => $candidateId,
            'job_id' => $jobId,
            'feedback_type' => $type->value,
            'is_relevant' => $type === EmployerRecommendationFeedbackType::RelevantCandidate,
            'feedback_key' => $key,
        ])->save();
    }
}
