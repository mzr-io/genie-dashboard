<?php

namespace App\Support\Observability;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/** Wraps the real processor so every finished span is scrubbed before it can be exported. */
final class ScrubbingSpanProcessor implements SpanProcessorInterface
{
    public function __construct(private readonly SpanProcessorInterface $delegate) {}

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->delegate->onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->delegate->onEnd(new ScrubbedReadableSpan($span));
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->forceFlush($cancellation);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->shutdown($cancellation);
    }
}
