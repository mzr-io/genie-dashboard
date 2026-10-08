<?php

namespace App\Platform\EditLock;

use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;

/**
 * The soft edit lock (Story 2.8; AR-19, AR-42): a TTL lock in the cache, an epoch in PostgreSQL and a flush-then-take-over
 * protocol. It is generic over a resource `type` and `id` and calls no module: the module that owns the resource registers
 * a {@see LockEpochs} for its type, and the caller supplies the holder's display name and `since` (the kernel never reads Access).
 *
 * One cache entry per resource, `edit_lock:{type}:{id}` through {@see TenantCache} (which adds the Workspace prefix), holds the
 * holder's membership ID, a random per-tab token, the display name and `since`, the epoch it was granted at, when it expires and
 * any pending flush request (the taker's membership, token, name and `since`). The cache may lose an entry, so a write is
 * checked against the epoch in PostgreSQL as well; the lock only decides who may edit, it never grants access.
 *
 * - `acquire`: a free or expired lock is granted; the same membership acquiring again (a new tab) replaces its own; a held lock
 *   answers the holder read-only.
 * - `heartbeat` and `release`: the token must match (a taker's release withdraws its pending take-over).
 * - `requestTakeover`: marks the lock `flush_requested`, emits the outbox event `platform.edit_lock.flush_requested` in the same
 *   transaction and answers `waiting`; `acknowledgeFlush` (the holder's confirmation) completes it at once.
 * - Completion happens on the acknowledgement, when the flush timeout passes without one (checked by the taker's poll and the
 *   holder's heartbeat), at once when no flush timeout is set, or when the lock has expired (an expired lock is simply free). It
 *   raises the resource's epoch in PostgreSQL, hands the lock to the taker at the new epoch and records, for the former holder's
 *   token, a one-shot `taken_over` notice saying whether the flush was acknowledged and who took over, and when.
 *
 * An unset TTL disables the lock: `acquire` answers `disabled` and nothing is stored.
 */
final class EditLock
{
    public function __construct(
        private readonly TenantCache $cache,
        private readonly Outbox $outbox,
        private readonly WorkspaceTransaction $transactions,
        private readonly EditLockSettings $settings,
        private readonly EditLockResources $resources,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->ttl() !== null;
    }

    public function acquire(string $workspaceId, string $type, string $id, string $membershipId, string $name, string $since): LockResult
    {
        $ttl = $this->settings->ttl();

        if ($ttl === null) {
            return new LockResult(LockStatus::Disabled);
        }

        return $this->exclusive($workspaceId, $type, $id, function () use ($workspaceId, $type, $id, $membershipId, $name, $since, $ttl): LockResult {
            $entry = $this->read($workspaceId, $type, $id);

            if ($entry !== null && $entry['membership_id'] !== $membershipId) {
                return $this->held($entry);
            }

            // A second tab of the same person replaces its own lock; a take-over somebody else asked for is not lost with it.
            return $this->grant($workspaceId, $type, $id, $membershipId, $this->token(), $name, $since, $ttl, $entry['flush'] ?? null);
        });
    }

    public function heartbeat(string $workspaceId, string $type, string $id, string $membershipId, string $token): LockResult
    {
        $ttl = $this->settings->ttl();

        if ($ttl === null) {
            return new LockResult(LockStatus::Disabled);
        }

        return $this->exclusive($workspaceId, $type, $id, function () use ($workspaceId, $type, $id, $membershipId, $token, $ttl): LockResult {
            $entry = $this->read($workspaceId, $type, $id);

            if (! $this->isHolder($entry, $membershipId, $token)) {
                return $this->gone($workspaceId, $type, $id, $token);
            }

            if ($this->flushDue($entry)) {
                // The holder did not flush in time: the take-over completes without it.
                $this->complete($workspaceId, $type, $id, $entry, false, $ttl);

                return $this->gone($workspaceId, $type, $id, $token);
            }

            $entry['expires_at'] = $this->now() + $ttl;
            $this->store($workspaceId, $type, $id, $entry);

            return $this->granted($entry, $ttl);
        });
    }

    public function release(string $workspaceId, string $type, string $id, string $membershipId, string $token): bool
    {
        if ($this->settings->ttl() === null) {
            return false;
        }

        return $this->exclusive($workspaceId, $type, $id, function () use ($workspaceId, $type, $id, $membershipId, $token): bool {
            $entry = $this->read($workspaceId, $type, $id);

            // A taker that closes its tab withdraws its request: the holder is no longer asked to flush for nobody.
            if ($entry !== null && $entry['flush'] !== null && $entry['flush']['membership_id'] === $membershipId && hash_equals($entry['flush']['token'], $token)) {
                $entry['flush'] = null;
                $this->store($workspaceId, $type, $id, $entry);

                return true;
            }

            if (! $this->isHolder($entry, $membershipId, $token)) {
                return false;
            }

            $this->cache->forget($workspaceId, $this->key($type, $id));

            return true;
        });
    }

    /**
     * Asks the holder to flush. Answers `waiting` with the taker's token (poll {@see takeoverStatus} with it), or `granted`
     * at once when the lock is free, expired, or no flush timeout is set. A request already pending from someone else answers `held`.
     */
    public function requestTakeover(string $workspaceId, string $type, string $id, string $membershipId, string $name, string $since): LockResult
    {
        $ttl = $this->settings->ttl();

        if ($ttl === null) {
            return new LockResult(LockStatus::Disabled);
        }

        return $this->exclusive($workspaceId, $type, $id, fn (): LockResult => $this->transactions->run($workspaceId, function () use ($workspaceId, $type, $id, $membershipId, $name, $since, $ttl): LockResult {
            $entry = $this->read($workspaceId, $type, $id);
            $token = $this->token();

            if ($entry === null) {
                return $this->grant($workspaceId, $type, $id, $membershipId, $token, $name, $since, $ttl);
            }

            if ($entry['flush'] !== null) {
                if ($entry['flush']['membership_id'] !== $membershipId) {
                    return $this->held($entry);
                }

                // The same person asking again (a retry, another tab) resumes its own pending request under the new token.
                $entry['flush']['token'] = $token;
                $entry['flush']['name'] = $name;
                $this->store($workspaceId, $type, $id, $entry);

                return $this->settle($workspaceId, $type, $id, $entry, $token, $ttl);
            }

            $entry['flush'] = ['membership_id' => $membershipId, 'token' => $token, 'name' => $name, 'since' => $since, 'requested_at' => $this->now()];

            // The event joins this transaction: a rollback removes it with the request.
            $this->outbox->emit(
                AuditAction::PlatformEditLockFlushRequested,
                $type.':'.$id,
                ['resource_type' => $type, 'resource_id' => $id, 'epoch' => $entry['epoch'], 'requested_by' => $membershipId],
                actor: $membershipId,
            );
            $this->store($workspaceId, $type, $id, $entry);

            return $this->settle($workspaceId, $type, $id, $entry, $token, $ttl);
        }));
    }

    /**
     * The taker's poll, with the token the request answered: `waiting`, `granted` (the lock is now theirs), `held` (someone else
     * holds it) or `free` (nobody does: the caller must acquire, a poll never grants). Only a token this kernel minted is accepted.
     */
    public function takeoverStatus(string $workspaceId, string $type, string $id, string $membershipId, string $token): LockResult
    {
        $ttl = $this->settings->ttl();

        if ($ttl === null) {
            return new LockResult(LockStatus::Disabled);
        }

        if (! self::isToken($token)) {
            return new LockResult(LockStatus::Lost);
        }

        return $this->exclusive($workspaceId, $type, $id, fn (): LockResult => $this->transactions->run($workspaceId, function () use ($workspaceId, $type, $id, $membershipId, $token, $ttl): LockResult {
            $entry = $this->read($workspaceId, $type, $id);

            if ($entry === null) {
                return new LockResult(LockStatus::Free);
            }

            if ($this->isHolder($entry, $membershipId, $token)) {
                return $this->granted($entry, max(1, $entry['expires_at'] - $this->now()));
            }

            if ($entry['flush'] !== null && $entry['flush']['membership_id'] === $membershipId && hash_equals($entry['flush']['token'], $token)) {
                return $this->settle($workspaceId, $type, $id, $entry, $token, $ttl);
            }

            return $this->held($entry);
        }));
    }

    /** A token as this kernel mints it: 32 lowercase hex characters. */
    public static function isToken(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/D', $token) === 1;
    }

    /** The holder confirms its flush: the take-over completes at once. */
    public function acknowledgeFlush(string $workspaceId, string $type, string $id, string $membershipId, string $token): LockResult
    {
        $ttl = $this->settings->ttl();

        if ($ttl === null) {
            return new LockResult(LockStatus::Disabled);
        }

        return $this->exclusive($workspaceId, $type, $id, fn (): LockResult => $this->transactions->run($workspaceId, function () use ($workspaceId, $type, $id, $membershipId, $token, $ttl): LockResult {
            $entry = $this->read($workspaceId, $type, $id);

            if (! $this->isHolder($entry, $membershipId, $token)) {
                return $this->gone($workspaceId, $type, $id, $token);
            }

            if ($entry['flush'] === null) {
                return $this->granted($entry, max(1, $entry['expires_at'] - $this->now()));
            }

            $this->complete($workspaceId, $type, $id, $entry, true, $ttl);

            return $this->gone($workspaceId, $type, $id, $token);
        }));
    }

    /** Whether the caller's token holds the lock at this epoch right now: what a write checks on top of the epoch in PostgreSQL. */
    public function holds(string $workspaceId, string $type, string $id, string $membershipId, string $token, int $epoch): bool
    {
        if ($this->settings->ttl() === null) {
            return false;
        }

        $entry = $this->read($workspaceId, $type, $id);

        return $entry !== null && $this->isHolder($entry, $membershipId, $token) && $entry['epoch'] === $epoch;
    }

    // ---- Internals -----------------------------------------------------------------------------------------------

    /**
     * Grants the lock to the caller at the resource's current epoch, keeping any take-over request already pending.
     *
     * @param  array<string, mixed>|null  $flush
     */
    private function grant(string $workspaceId, string $type, string $id, string $membershipId, string $token, string $name, string $since, int $ttl, ?array $flush = null): LockResult
    {
        $epoch = $this->transactions->run($workspaceId, fn (): ?int => $this->resources->for($type)->current($workspaceId, $id)) ?? throw new EditLockResourceMissing;

        $entry = [
            'membership_id' => $membershipId,
            'token' => $token,
            'name' => $name,
            'since' => $since,
            'epoch' => $epoch,
            'expires_at' => $this->now() + $ttl,
            'flush' => $flush,
        ];
        $this->store($workspaceId, $type, $id, $entry);

        return $this->granted($entry, $ttl);
    }

    /**
     * Completes the take-over if it is due (no flush timeout, or the timeout has passed); otherwise the taker waits.
     *
     * @param  array<string, mixed>  $entry
     */
    private function settle(string $workspaceId, string $type, string $id, array $entry, string $takerToken, int $ttl): LockResult
    {
        if (! $this->flushDue($entry)) {
            return new LockResult(LockStatus::Waiting, token: $takerToken);
        }

        $new = $this->complete($workspaceId, $type, $id, $entry, false, $ttl);

        return $this->granted($new, $ttl);
    }

    /**
     * A pending flush is due when no timeout is configured (the take-over does not wait) or the timeout has passed.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function flushDue(?array $entry): bool
    {
        if ($entry === null || $entry['flush'] === null) {
            return false;
        }

        $timeout = $this->settings->flushTimeout();

        return $timeout === null || $this->now() >= $entry['flush']['requested_at'] + $timeout;
    }

    /**
     * Raises the epoch in PostgreSQL, hands the lock to the taker at the new epoch and notes for the former holder's token
     * that it was taken over.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed> the taker's new entry
     */
    private function complete(string $workspaceId, string $type, string $id, array $entry, bool $acknowledged, int $ttl): array
    {
        $taker = $entry['flush'];

        // The epoch and the trail of the take-over (IDs and enums only) are one transaction.
        $epoch = $this->transactions->run($workspaceId, function () use ($workspaceId, $type, $id, $entry, $taker, $acknowledged): int {
            $epoch = $this->resources->for($type)->increment($workspaceId, $id) ?? throw new EditLockResourceMissing;
            $this->outbox->emit(
                AuditAction::PlatformEditLockTaken,
                $type.':'.$id,
                ['resource_type' => $type, 'resource_id' => $id, 'taken_by' => $taker['membership_id'], 'former_holder' => $entry['membership_id'], 'flush_acknowledged' => $acknowledged, 'epoch' => $epoch],
                actor: $taker['membership_id'],
            );

            return $epoch;
        });

        $new = [
            'membership_id' => $taker['membership_id'],
            'token' => $taker['token'],
            'name' => $taker['name'],
            'since' => $taker['since'],
            'epoch' => $epoch,
            'expires_at' => $this->now() + $ttl,
            'flush' => null,
        ];
        $this->store($workspaceId, $type, $id, $new);
        $this->cache->put(
            $workspaceId,
            $this->noticeKey($type, $id, $entry['token']),
            ['flush_acknowledged' => $acknowledged, 'by_name' => $taker['name'], 'at' => gmdate('Y-m-d\TH:i:s\Z', $this->now())],
            $ttl,
        );

        return $new;
    }

    /** What a caller that does not hold the lock is told: it was taken over (once), or it is simply gone. */
    private function gone(string $workspaceId, string $type, string $id, string $token): LockResult
    {
        $key = $this->noticeKey($type, $id, $token);
        $notice = $this->cache->get($workspaceId, $key);

        if (! is_array($notice)) {
            return new LockResult(LockStatus::Lost);
        }

        $this->cache->forget($workspaceId, $key);

        return new LockResult(
            LockStatus::TakenOver,
            holderName: is_string($notice['by_name'] ?? null) ? $notice['by_name'] : null,
            holderSince: is_string($notice['at'] ?? null) ? $notice['at'] : null,
            flushAcknowledged: ($notice['flush_acknowledged'] ?? false) === true,
        );
    }

    /** @param  array<string, mixed>  $entry */
    private function granted(array $entry, int $ttl): LockResult
    {
        return new LockResult(LockStatus::Granted, $entry['token'], $entry['epoch'], $ttl, $entry['name'], $entry['since'], flushRequested: $entry['flush'] !== null);
    }

    /** @param  array<string, mixed>  $entry */
    private function held(array $entry): LockResult
    {
        return new LockResult(LockStatus::Held, epoch: $entry['epoch'], holderName: $entry['name'], holderSince: $entry['since']);
    }

    /** @param  array<string, mixed>|null  $entry */
    private function isHolder(?array $entry, string $membershipId, string $token): bool
    {
        return $entry !== null && $entry['membership_id'] === $membershipId && hash_equals($entry['token'], $token);
    }

    /**
     * The live entry, or null when there is none, it has expired or it is malformed.
     *
     * @return array{membership_id: string, token: string, name: string, since: string, epoch: int, expires_at: int, flush: array{membership_id: string, token: string, name: string, since: string, requested_at: int}|null}|null
     */
    private function read(string $workspaceId, string $type, string $id): ?array
    {
        $entry = $this->cache->get($workspaceId, $this->key($type, $id));

        if (! is_array($entry)) {
            return null;
        }

        foreach (['membership_id', 'token', 'name', 'since'] as $field) {
            if (! is_string($entry[$field] ?? null)) {
                return null;
            }
        }

        if (! is_int($entry['epoch'] ?? null) || ! is_int($entry['expires_at'] ?? null) || $entry['expires_at'] <= $this->now()) {
            return null;
        }

        $flush = $entry['flush'] ?? null;

        if ($flush !== null && (! is_array($flush) || ! is_string($flush['membership_id'] ?? null) || ! is_string($flush['token'] ?? null) || ! is_string($flush['name'] ?? null) || ! is_string($flush['since'] ?? null) || ! is_int($flush['requested_at'] ?? null))) {
            return null;
        }

        return [
            'membership_id' => $entry['membership_id'],
            'token' => $entry['token'],
            'name' => $entry['name'],
            'since' => $entry['since'],
            'epoch' => $entry['epoch'],
            'expires_at' => $entry['expires_at'],
            'flush' => $flush === null ? null : [
                'membership_id' => $flush['membership_id'],
                'token' => $flush['token'],
                'name' => $flush['name'],
                'since' => $flush['since'],
                'requested_at' => $flush['requested_at'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $entry  stored for the time it has left, so a take-over request never extends the holder's lock */
    private function store(string $workspaceId, string $type, string $id, array $entry): void
    {
        $this->cache->put($workspaceId, $this->key($type, $id), $entry, (int) max(1, $entry['expires_at'] - $this->now()));
    }

    /**
     * Runs `$callback` as the only caller on this resource.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     *
     * @throws EditLockBusy when another caller held the resource for too long
     */
    private function exclusive(string $workspaceId, string $type, string $id, \Closure $callback): mixed
    {
        try {
            return $this->cache->lock($workspaceId, $this->key($type, $id), 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new EditLockBusy;
        }
    }

    private function key(string $type, string $id): string
    {
        EditLockResources::assertType($type);

        return 'edit_lock:'.$type.':'.strtolower($id);
    }

    private function noticeKey(string $type, string $id, string $token): string
    {
        return 'edit_lock_notice:'.$type.':'.strtolower($id).':'.hash('sha256', $token);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }
}
