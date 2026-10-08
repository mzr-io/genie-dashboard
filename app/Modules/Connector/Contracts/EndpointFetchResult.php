<?php

namespace App\Modules\Connector\Contracts;

/**
 * What a scheduled fetch brought back (Story 2.14). A good answer is `ok` and carries the exact body text, which only the caller that
 * stores it reads; a failure carries the user code and the reason of the error ladder (the numbers it knew, never a URL, a value or a
 * body). `moved` means the Endpoint or Data Source revision is no longer the one asked for: nothing was sent.
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
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['ok' => $this->ok, 'status' => $this->status, 'code' => $this->code?->value, 'reason' => $this->reason];
    }
}
