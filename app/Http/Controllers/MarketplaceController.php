<?php

namespace App\Http\Controllers;

use App\Http\Resources\CandidateCardResource;
use App\Http\Resources\JobResource;
use App\Jobs\SendNewApplicationNotification;
use App\Models\Job;
use App\Services\CandidateBehavioralProfileService;
use App\Services\JobSearchService;
use App\Services\MatchOutcomeService;
use App\Services\MatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketplaceController extends Controller
{
    public function __construct(
        private JobSearchService $searchService,
        private MatchService $matchService,
        private CandidateBehavioralProfileService $behavior,
        private MatchOutcomeService $outcomes,
    ) {}

    public function searchJobs(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'industry' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'work_preference' => 'nullable|string',
            'salary_min' => 'nullable|numeric|min:0',
            'salary_max' => 'nullable|numeric|min:0',
            'experience_min' => 'nullable|integer|min:0',
            'skills' => 'nullable|array',
            'skills.*' => 'nullable|string',
            'company_size' => 'nullable|string',
            'is_verified' => 'nullable|boolean',
            'sort_by' => 'nullable|in:newest,relevance,salary_high,salary_low',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = $this->searchService->search($filters);

        if (auth()->check() && auth()->user()->isCandidate() && ! empty($filters['keyword'])) {
            $this->behavior->recordSearch(auth()->user(), $filters['keyword'], $filters);
        }

        $sortBy = $filters['sort_by'] ?? 'newest';
        match ($sortBy) {
            'newest' => $query->latest(),
            'salary_high' => $query->orderByDesc('salary_max'),
            'salary_low' => $query->orderByAsc('salary_min'),
            default => $query->latest(),
        };

        $perPage = $filters['per_page'] ?? 15;
        $jobs = $query->paginate($perPage);

        if ($sortBy === 'relevance' && auth()->check() && auth()->user()->isCandidate()) {
            $jobs->getCollection()->transform(function ($job) {
                $job->match_score = $this->matchService->calculateMatchScore(auth()->user(), $job);

                return $job;
            });
            $jobs->getCollection()->sortByDesc('match_score');
        }

        return JobResource::collection($jobs);
    }

    public function getJob(string $job): JsonResponse
    {
        $job = Job::with(['company', 'jobSkills.skill', 'employer'])
            ->where('slug', $job)
            ->orWhere('id', $job)
            ->firstOrFail();

        $response = (new JobResource($job))->toArray(request());

        if (auth()->check() && auth()->user()->isCandidate()) {
            $this->behavior->recordView(auth()->user(), $job);
            $this->outcomes->jobViewed(auth()->user(), $job);

            $response['match_score'] = $this->matchService->calculateMatchScore(auth()->user(), $job);
            $response['has_applied'] = auth()->user()
                ->jobApplications()
                ->where('job_id', $job->id)
                ->exists();
        }

        return response()->json($response);
    }

    public function getSearchFilters(): JsonResponse
    {
        return response()->json([
            'industries' => $this->searchService->getUniqueIndustries(),
            'employment_types' => $this->searchService->getUniqueEmploymentTypes(),
            'work_preferences' => $this->searchService->getUniqueWorkPreferences(),
            'popular_skills' => $this->searchService->getPopularSkills(),
        ]);
    }

    public function getRecommendedJobs(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = $request->input('per_page', 10);
        $candidate = $request->user();

        $matches = $this->matchService->getTopMatchingJobs($candidate, $perPage);

        $jobs = $matches->pluck('job')->each(function ($job) use ($matches) {
            $match = $matches->firstWhere('job.id', $job->id);
            $job->match_score = $match['match_score'] ?? $match['match_percentage'] ?? 0;
        });

        $jobs->load(['company', 'jobSkills.skill']);

        return JobResource::collection($jobs);
    }

    public function getCandidatesForJob(Request $request, Job $job): AnonymousResourceCollection
    {
        $perPage = $request->input('per_page', 15);

        $matches = $this->matchService->getTopMatchingCandidates($job, $perPage);

        $candidates = $matches->pluck('candidate')->each(function ($candidate) use ($matches) {
            $match = $matches->firstWhere('candidate.id', $candidate->id);
            $candidate->match_score = $match['match_score'] ?? $match['match_percentage'] ?? 0;
        });

        $candidates->load([
            'candidateProfile',
            'candidateSkills.skill',
            'candidateAssessment',
            'candidateMedia',
            'candidatePreferences',
        ]);

        return CandidateCardResource::collection($candidates);
    }

    public function applyForJob(Request $request, Job $job): JsonResponse
    {
        $candidate = $request->user();

        if (! $candidate->isCandidate()) {
            return response()->json(['error' => 'Only candidates can apply for jobs'], 403);
        }

        if (! $candidate->onboarding_completed) {
            return response()->json(['error' => 'Please complete your onboarding before applying'], 400);
        }

        $existingApplication = $candidate->jobApplications()
            ->where('job_id', $job->id)
            ->first();

        if ($existingApplication) {
            return response()->json(['error' => 'You have already applied for this job'], 400);
        }

        $matchScore = $this->matchService->calculateMatchScore($candidate, $job);

        $application = $candidate->jobApplications()->create([
            'job_id' => $job->id,
            'status' => 'applied',
            'match_score' => $matchScore,
        ]);

        $this->behavior->recordApply($candidate, $job);
        $this->outcomes->jobApplied($candidate, $job, $application, $matchScore);

        SendNewApplicationNotification::notify($application);

        return response()->json([
            'message' => 'Application submitted successfully',
            'application' => $application,
            'match_score' => $matchScore,
        ], 201);
    }
}
