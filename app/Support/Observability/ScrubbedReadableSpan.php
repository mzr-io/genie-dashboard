<?php

namespace App\Support\Observability;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;

final class ScrubbedReadableSpan implements ReadableSpanInterface
{
    public function __construct(private readonly ReadableSpanInterface $span) {}

    public function getName(): string
    {
        return Scrubber::text($this->span->getName());
    }

    public function getContext(): SpanContextInterface
    {
        return $this->span->getContext();
    }

    public function getParentContext(): SpanContextInterface
    {
        return $this->span->getParentContext();
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->span->getInstrumentationScope();
    }

    public function hasEnded(): bool
    {
        return $this->span->hasEnded();
    }

    public function toSpanData(): SpanDataInterface
    {
        return new ScrubbedSpanData($this->span->toSpanData());
    }

    public function getDuration(): int
    {
        return $this->span->getDuration();
    }

    public function getKind(): int
    {
        return $this->span->getKind();
    }

    public function getAttribute(string $key)
    {
        return Scrubber::spanAttributes([$key => $this->span->getAttribute($key)])[$key] ?? null;
    }
}
