<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Notifications\InterviewScheduled;
use App\Services\MatchOutcomeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployerInterviewController extends Controller
{
    public function __construct(protected MatchOutcomeService $outcomes) {}

    public function index(Request $request)
    {
        $user = Auth::user();

        $interviews = Interview::where('employer_id', $user->id)
            ->with(['candidate.candidateProfile', 'job'])
            ->orderBy('scheduled_at', 'asc')
            ->paginate(15);

        return view('employer.interviews.index', [
            'interviews' => $interviews,
        ]);
    }

    public function create(Request $request)
    {
        $candidateId = $request->get('candidate_id');
        $jobId = $request->get('job_id');

        $candidates = User::where('role', 'candidate')
            ->where('onboarding_completed', true)
            ->with('candidateProfile')
            ->get();

        $jobs = Job::where('employer_id', Auth::id())
            ->where('status', 'open')
            ->get();

        return view('employer.interviews.create', [
            'candidates' => $candidates,
            'jobs' => $jobs,
            'selectedCandidateId' => $candidateId,
            'selectedJobId' => $jobId,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'candidate_id' => 'required|exists:users,id',
            'job_id' => 'nullable|exists:job_listings,id',
            'interview_type' => 'required|in:phone,video,onsite,technical,behavioral,panel',
            'scheduled_at' => 'required|date|after:now',
            'notes' => 'nullable|string',
        ]);

        $interview = Interview::create([
            'employer_id' => Auth::id(),
            'candidate_id' => $validated['candidate_id'],
            'job_id' => $validated['job_id'] ?? null,
            'interview_type' => $validated['interview_type'],
            'scheduled_at' => $validated['scheduled_at'],
            'notes' => $validated['notes'] ?? null,
            'status' => Interview::STATUS_SCHEDULED,
        ]);

        $this->outcomes->interviewScheduled($interview);

        return redirect()->route('employer.interviews.index')
            ->with('success', 'Interview scheduled successfully!');
    }

    public function show(Request $request, Interview $interview)
    {
        $this->authorize('view', $interview);

        $interview->load(['candidate.candidateProfile', 'job', 'employer']);

        return view('employer.interviews.show', [
            'interview' => $interview,
        ]);
    }

    public function update(Request $request, Interview $interview)
    {
        $this->authorize('update', $interview);

        $validated = $request->validate([
            'status' => 'required|in:scheduled,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $interview->update($validated);

        return redirect()->route('employer.interviews.index')
            ->with('success', 'Interview updated successfully!');
    }

    public function cancel(Request $request, Interview $interview)
    {
        $this->authorize('update', $interview);

        $interview->update(['status' => Interview::STATUS_CANCELLED]);

        return redirect()->route('employer.interviews.index')
            ->with('success', 'Interview cancelled successfully!');
    }

    public function complete(Request $request, Interview $interview)
    {
        $this->authorize('update', $interview);

        $interview->update(['status' => Interview::STATUS_COMPLETED]);

        $this->outcomes->interviewCompleted($interview);

        return redirect()->route('employer.interviews.index')
            ->with('success', 'Interview marked as completed!');
    }

    public function scheduleFromApplication(Request $request, JobApplication $application)
    {
        $job = $application->job;
        $candidate = $application->candidate;

        if ($job->employer_id !== Auth::id()) {
            abort(403, 'You can only schedule interviews for your own jobs.');
        }

        return view('employer.interviews.schedule', [
            'application' => $application,
            'job' => $job,
            'candidate' => $candidate,
        ]);
    }

    public function storeFromApplication(Request $request, JobApplication $application)
    {
        $job = $application->job;

        if ($job->employer_id !== Auth::id()) {
            abort(403, 'You can only schedule interviews for your own jobs.');
        }

        $validated = $request->validate([
            'scheduled_date' => 'required|date|after_or_equal:today',
            'scheduled_time' => 'required',
            'interview_type' => 'required|in:video,physical,phone',
            'meeting_link' => 'nullable|url',
            'location' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $scheduledAt = $validated['scheduled_date'].' '.$validated['scheduled_time'];

        $interview = Interview::create([
            'employer_id' => Auth::id(),
            'candidate_id' => $application->candidate_id,
            'job_id' => $application->job_id,
            'job_application_id' => $application->id,
            'interview_type' => $validated['interview_type'],
            'scheduled_at' => $scheduledAt,
            'meeting_link' => $validated['meeting_link'] ?? null,
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => Interview::STATUS_SCHEDULED,
        ]);

        $this->outcomes->interviewScheduled($interview);

        $application->update([
            'status' => JobApplication::STATUS_INTERVIEW,
            'interview_at' => $scheduledAt,
        ]);

        $application->candidate->notify(new InterviewScheduled($interview));

        return redirect()->route('employer.jobs.pipeline', ['job' => $application->job_id])
            ->with('success', 'Interview scheduled successfully!');
    }
}
