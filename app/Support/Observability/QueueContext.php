<?php

namespace App\Support\Observability;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
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

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($context): void {
            $payload = $event->job->payload();
            $context->enter($payload['request_id'] ?? null);
        });
        $events->listen(JobProcessed::class, fn () => $context->leave());
        $events->listen(JobExceptionOccurred::class, fn () => $context->leave());
    }
}
