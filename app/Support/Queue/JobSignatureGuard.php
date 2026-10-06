<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Verifies the payload signature before a job is unserialized or run.
 *
 * Runs on `JobProcessing`, which the worker raises before `$job->fire()` (the
 * only place the command is unserialized). A bad payload is deleted, never
 * retried and never handed to the job's own `failed()` hook (that would
 * unserialize it). `JobFailed` is raised so failed-job storage and Horizon
 * record it. Only the metadata below is logged, never payload content.
 */
final class JobSignatureGuard
{
    public const SECURITY_EVENT = 'security.queue.job_signature_invalid';

    /** The signer resolves lazily: APP_KEY may not exist yet while the framework boots (key:generate). */
    public function __construct(private readonly Container $app, private readonly Dispatcher $events) {}

    public static function register(Container $app, Dispatcher $events): void
    {
        $guard = new self($app, $events);

        $events->listen(JobProcessing::class, $guard->handle(...));
    }

    public function handle(JobProcessing $event): void
    {
        // Only drivers that sign their payloads are verified (sync and database queues are not signed).
        if (config("queue.connections.{$event->connectionName}.driver") !== 'redis') {
            return;
        }

        $payload = json_decode($event->job->getRawBody(), true);
        $reason = match (true) {
            ! is_array($payload) => 'malformed',
            ! isset($payload[JobSigner::FIELD]) => 'missing',
            ! $this->app->make(JobSigner::class)->verify($payload) => 'mismatch',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        $this->reject($event, $reason, is_array($payload) ? $payload : []);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function reject(JobProcessing $event, string $reason, array $payload): void
    {
        $job = $event->job;
        $uuid = $payload['uuid'] ?? null;

        try {
            Log::error(self::SECURITY_EVENT, [
                'security_event' => self::SECURITY_EVENT,
                'reason' => $reason,
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'job_uuid' => is_string($uuid) && preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 ? $uuid : null,
            ]);
        } catch (Throwable) {
            // Logging must never decide whether a forged job runs.
        }

        $this->discard($job);

        $this->events->dispatch(new JobFailed(
            $event->connectionName,
            $job,
            new RuntimeException('Queued job rejected: invalid signature ('.$reason.').'),
        ));
    }

    private function discard(Job $job): void
    {
        $job->markAsFailed();
        $job->delete();
    }
}
