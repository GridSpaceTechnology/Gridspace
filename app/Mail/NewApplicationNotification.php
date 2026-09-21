<?php

namespace App\Mail;

use App\Models\CandidatePersonalityProfile;
use App\Models\CandidateProfile;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\JobMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * GridSpace branded "new application" email sent to the employer who owns
 * the job the candidate applied to.
 */
class NewApplicationNotification extends Mailable
{
    use Queueable, SerializesModels;

    public JobApplication $application;

    public Job $job;

    public User $candidate;

    public ?CandidateProfile $profile;

    public ?CandidatePersonalityProfile $personality;

    public ?string $coverLetter;

    /** @var array<string, mixed> The canonical match analysis for the email. */
    public array $match;

    public bool $hasCv;

    public ?string $cvPath;

    public string $viewApplicationUrl;

    public function __construct(JobApplication $application)
    {
        $this->application = $application;

        $this->application->load([
            'job.employer',
            'candidate.candidateProfile',
            'candidate.candidateSkills.skill',
            'candidate.candidateMedia',
            'candidate.personalityProfile',
        ]);

        $this->job = $this->application->job;
        $this->candidate = $this->application->candidate;
        $this->profile = $this->candidate->candidateProfile;
        $this->personality = $this->candidate->personalityProfile;
        $this->coverLetter = $this->application->candidate_note;
        $this->cvPath = $this->resolveCvPath();
        $this->hasCv = $this->cvPath !== null;
        $this->viewApplicationUrl = route('employer.ats.show', $this->application);
        $this->match = $this->buildMatchData();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "New Application for {$this->job->title} — {$this->candidate->name} | GridSpace",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employer.new-application',
            with: [
                'application' => $this->application,
                'job' => $this->job,
                'candidate' => $this->candidate,
                'profile' => $this->profile,
                'personality' => $this->personality,
                'coverLetter' => $this->coverLetter,
                'match' => $this->match,
                'hasCv' => $this->hasCv,
                'candidateSkills' => $this->candidate->candidateSkills,
                'viewApplicationUrl' => $this->viewApplicationUrl,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->hasCv) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('public', $this->cvPath)->as($this->cvAttachmentName()),
        ];
    }

    public function cvAttachmentName(): string
    {
        return Str::slug($this->candidate->name).'-CV.'.pathinfo((string) $this->cvPath, PATHINFO_EXTENSION);
    }

    protected function resolveCvPath(): ?string
    {
        $path = $this->candidate->candidateMedia?->cv_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMatchData(): array
    {
        $overall = (int) $this->application->match_score;

        try {
            $breakdown = app(JobMatchingService::class)->calculateBreakdown($this->candidate, $this->job);
        } catch (Throwable $e) {
            Log::warning('Unable to build match analysis for application notification', [
                'application_id' => $this->application->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'overall' => $overall,
                'category' => null,
                'components' => [],
                'reasons' => [],
                'strengths' => [],
                'matched_skills' => [],
                'missing_skills' => [],
            ];
        }

        return [
            'overall' => $overall > 0 ? $overall : (int) ($breakdown['overall_score'] ?? 0),
            'category' => $breakdown['category'] ?? null,
            'components' => $this->presentComponents($breakdown['components'] ?? []),
            'reasons' => array_slice((array) ($breakdown['reasons'] ?? []), 0, 6),
            'strengths' => array_slice((array) ($breakdown['strengths'] ?? []), 0, 4),
            'matched_skills' => (array) ($breakdown['matched_skills'] ?? []),
            'missing_skills' => (array) ($breakdown['missing_skills'] ?? []),
        ];
    }

    /**
     * @return array<int, array{label: string, score: int, reasons: array<int, string>}>
     */
    protected function presentComponents(array $components): array
    {
        $labels = [
            'skills' => 'Skills Match',
            'role' => 'Professional / Role Compatibility',
            'experience' => 'Experience Match',
            'personality' => 'Work Style / Personality Compatibility',
            'work_preference' => 'Work Arrangement',
            'salary' => 'Salary Compatibility',
            'education' => 'Education Match',
            'availability' => 'Availability',
        ];

        $presented = [];

        foreach ($labels as $key => $label) {
            $component = $components[$key] ?? null;

            if ($component === null || ($component['applicable'] ?? true) === false) {
                continue;
            }

            $presented[] = [
                'label' => $label,
                'score' => (int) $component['score'],
                'reasons' => array_slice((array) ($component['reasons'] ?? []), 0, 2),
            ];
        }

        return $presented;
    }
}
