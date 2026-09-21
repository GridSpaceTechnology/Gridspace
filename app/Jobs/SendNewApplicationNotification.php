<?php

namespace App\Jobs;

use App\Mail\NewApplicationNotification;
use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the employer who owns the job that a candidate just applied to.
 *
 * The notification is dispatched only after the application has been
 * successfully persisted and is best-effort: mail delivery failures are
 * retried by the queue and never affect the submitted application.
 */
class SendNewApplicationNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public JobApplication $application) {}

    /**
     * Queue the notification, absorbing queue wiring failures so the
     * candidate's application response is never affected.
     */
    public static function notify(JobApplication $application): void
    {
        try {
            self::dispatch($application);
        } catch (Throwable $e) {
            Log::error('Failed to queue new application notification', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        $application = $this->application->load(['job.employer']);

        $job = $application->job;
        $employer = $job?->employer;

        if (! $job || ! $employer) {
            Log::warning('Cannot send new application notification - job or employer missing', [
                'application_id' => $application->id,
            ]);

            return;
        }

        if (! filter_var($employer->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Cannot send new application notification - employer email is missing or invalid', [
                'application_id' => $application->id,
                'employer_id' => $employer->id,
            ]);

            return;
        }

        Mail::to($employer->email)->send(new NewApplicationNotification($application));
    }

    public function failed(?Throwable $e): void
    {
        Log::error('New application notification permanently failed', [
            'application_id' => $this->application->id,
            'error' => $e?->getMessage(),
        ]);
    }
}
