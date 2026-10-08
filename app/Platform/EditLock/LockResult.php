<?php

namespace App\Platform\EditLock;

/**
 * The answer of an edit-lock call. For `held` the holder is whoever has the lock; for `taken_over` it is who took the lock
 * over and when. A token is only ever set for the caller that holds (or is waiting for) the lock.
 */
final readonly class LockResult
{
    public function __construct(
        public LockStatus $status,
        public ?string $token = null,
        public ?int $epoch = null,
        public ?int $ttlSeconds = null,
        public ?string $holderName = null,
        public ?string $holderSince = null,
        /** The holder is asked to flush: someone requested a take-over. */
        public bool $flushRequested = false,
        public bool $flushAcknowledged = false,
    ) {}

    public function holds(): bool
    {
        return $this->status === LockStatus::Granted;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status->value, 'epoch' => $this->epoch];
    }
}
