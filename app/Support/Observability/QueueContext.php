<?php

namespace App\Support\Observability;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;

/** Carries `request_id` in queued payloads and restores it around job processing. */
final class QueueContext
{
    public static function register(RequestContext $context, Dispatcher $events): void
    {
        Queue::createPayloadUsing(
            fn (): array => ['request_id' => $context->requestId()],
        );

        // A failed job never reaches JobProcessed, so its frame is still open when the next job starts.
        $open = false;

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($context, &$open): void {
            if ($open) {
                $context->leave();
            }
            $payload = $event->job->payload();
            $context->enter($payload['request_id'] ?? null);
            $open = true;
        });
        $events->listen(JobProcessed::class, function () use ($context, &$open): void {
            $open = false;
            $context->leave();
        });
    }
}
