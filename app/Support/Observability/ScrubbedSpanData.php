<?php

namespace App\Support\Observability;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\Link;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;

/** Read-only view of finished span data with the allowlist and URL scrubbing applied. */
final class ScrubbedSpanData implements SpanDataInterface
{
    public function __construct(private readonly SpanDataInterface $data) {}

    public function getName(): string
    {
        return Scrubber::text($this->data->getName());
    }

    public function getKind(): int
    {
        return $this->data->getKind();
    }

    public function getContext(): SpanContextInterface
    {
        return $this->data->getContext();
    }

    public function getParentContext(): SpanContextInterface
    {
        return $this->data->getParentContext();
    }

    public function getTraceId(): string
    {
        return $this->data->getTraceId();
    }

    public function getSpanId(): string
    {
        return $this->data->getSpanId();
    }

    public function getParentSpanId(): string
    {
        return $this->data->getParentSpanId();
    }

    public function getStatus(): StatusDataInterface
    {
        $status = $this->data->getStatus();
        $description = $status->getDescription();

        /** @var 'Error'|'Ok'|'Unset' $code */
        $code = $status->getCode();

        return new StatusData($code, Scrubber::text($description));
    }

    public function getStartEpochNanos(): int
    {
        return $this->data->getStartEpochNanos();
    }

    /** @return AttributesInterface<string, mixed> */
    public function getAttributes(): AttributesInterface
    {
        return Attributes::create(Scrubber::spanAttributes($this->data->getAttributes()->toArray()));
    }

    public function getEvents(): array
    {
        return array_map(
            fn ($event) => new Event(
                Scrubber::text($event->getName()),
                $event->getEpochNanos(),
                Attributes::create(Scrubber::spanAttributes($event->getAttributes()->toArray())),
            ),
            $this->data->getEvents(),
        );
    }

    public function getLinks(): array
    {
        return array_map(
            fn ($link) => new Link($link->getSpanContext(), Attributes::create([])),
            $this->data->getLinks(),
        );
    }

    public function getEndEpochNanos(): int
    {
        return $this->data->getEndEpochNanos();
    }

    public function hasEnded(): bool
    {
        return $this->data->hasEnded();
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->data->getInstrumentationScope();
    }

    public function getResource(): ResourceInfo
    {
        $resource = $this->data->getResource();

        return ResourceInfo::create(
            Attributes::create(Scrubber::resourceAttributes($resource->getAttributes()->toArray())),
            $resource->getSchemaUrl(),
        );
    }

    public function getTotalDroppedEvents(): int
    {
        return $this->data->getTotalDroppedEvents();
    }

    public function getTotalDroppedLinks(): int
    {
        return $this->data->getTotalDroppedLinks();
    }
}
