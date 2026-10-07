<?php

namespace App\Support\Observability;

use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\Span;

/**
 * Holds the correlation ids for the request or job being handled in this process.
 */
final class RequestContext
{
    public const PATTERN = '/\A[A-Za-z0-9._-]{8,64}\z/D';

    private ?string $requestId = null;

    private ?string $workspaceId = null;

    /** @var list<array{0: ?string, 1: ?string}> */
    private array $stack = [];

    /** A valid candidate is kept; anything else (including null) is replaced by a fresh ULID. */
    public static function sanitize(mixed $candidate): string
    {
        if (is_string($candidate) && preg_match(self::PATTERN, $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::ulid();
    }

    public function begin(mixed $candidate): string
    {
        $this->stack = [];
        $this->workspaceId = null;
        $this->requestId = self::sanitize($candidate);
        $this->tagCurrentSpan();

        return $this->requestId;
    }

    /** Run a job under the request id it was dispatched with; the previous context returns on leave(). */
    public function enter(mixed $candidate): void
    {
        $this->stack[] = [$this->requestId, $this->workspaceId];
        $this->workspaceId = null;
        $this->requestId = self::sanitize($candidate);
        $this->tagCurrentSpan();
    }

    public function leave(): void
    {
        if ($this->stack === []) {
            $this->clear();

            return;
        }

        [$this->requestId, $this->workspaceId] = array_pop($this->stack);
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->workspaceId = null;
        $this->stack = [];
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function workspaceId(): ?string
    {
        return $this->workspaceId;
    }

    public function setWorkspaceId(?string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        if ($workspaceId !== null) {
            Span::getCurrent()->setAttribute('dashflow.workspace_id', $workspaceId);
        }
    }

    public function traceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    private function tagCurrentSpan(): void
    {
        if ($this->requestId !== null) {
            Span::getCurrent()->setAttribute('dashflow.request_id', $this->requestId);
        }
    }
}
