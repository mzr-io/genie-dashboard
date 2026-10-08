<?php

namespace App\Modules\Connector\Contracts;

/**
 * What a scheduled fetch brought back (Story 2.14). A good answer is `ok` and carries the exact body text, which only the caller that
 * stores it reads; a failure carries the user code and the reason of the error ladder (the numbers it knew, never a URL, a value or a
 * body). `notModified` (Story 2.15) is a 304 to the conditional request that was sent: the caller keeps what it has. `moved` means the Endpoint or Data Source revision is no longer the one asked for: nothing was sent.
 */
final readonly class EndpointFetchResult
{
    /**
     * @param  list<string>  $parameterNames
     */
    public function __construct(
        public bool $ok,
        #[\SensitiveParameter] public ?string $body,
        public ?int $status,
        public ?int $latencyMs,
        public ?int $bytes,
        public ?ConnectionTestCode $code,
        public ?string $reason,
        public string $urlTemplate,
        public array $parameterNames,
        public ?int $limitBytes = null,
        public ?int $page = null,
        public ?int $pages = null,
        public bool $moved = false,
        public ?string $dataSourceId = null,
        /** Story 2.15: a 304 answer to a conditional request this fetch sent. `ok` is true and there is no body. */
        public bool $notModified = false,
        /** Story 2.15: the response's `ETag` (a 200 or a 304) when it is exactly one value of at most 512 visible ASCII characters, else null. Unpaged only. */
        public ?string $etag = null,
        /** Story 2.15: the response's `Last-Modified`, under the same rule. */
        public ?string $lastModified = null,
    ) {}

    /** The reason of the failed run for a 304 nothing conditional was sent for (or the target has no payload to keep). */
    public const NOT_MODIFIED_WITHOUT_PAYLOAD = 'not_modified_without_payload';

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['ok' => $this->ok, 'status' => $this->status, 'code' => $this->code?->value, 'reason' => $this->reason];
    }
}
