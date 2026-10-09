<?php

namespace App\Modules\Connector\Contracts;

/**
 * The guard's decision for one URL. `pinnedIp` is the address that was checked (set only when allowed) and is for the
 * operator and the transport alone; the decision trail holds reason codes and counts, never an address.
 */
final readonly class EgressVerdict
{
    /**
     * @param  list<string>  $trail
     */
    private function __construct(
        public bool $allowed,
        public ?EgressReason $reason,
        public string $scheme,
        public string $host,
        public ?int $port,
        public ?string $pinnedIp,
        public array $trail,
    ) {}

    /** @param  list<string>  $trail */
    public static function allowed(string $scheme, string $host, int $port, string $pinnedIp, array $trail): self
    {
        return new self(true, null, $scheme, $host, $port, $pinnedIp, $trail);
    }

    /** @param  list<string>  $trail */
    public static function denied(EgressReason $reason, string $scheme, string $host, ?int $port, array $trail): self
    {
        return new self(false, $reason, $scheme, $host, $port, null, $trail);
    }

    public function code(): ?ErrorCode
    {
        return $this->allowed ? null : ErrorCode::SsrfBlocked;
    }

    public function messageKey(): ?string
    {
        return $this->reason?->messageKey();
    }
}
