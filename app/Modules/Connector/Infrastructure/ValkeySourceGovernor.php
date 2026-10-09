<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\Admission;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\GovernorLimits;
use App\Modules\Connector\Contracts\SourceGovernor;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The {@see SourceGovernor} on the Valkey `queue` store (noeviction), per `(workspace_id, data_source_id)` (Story 2.17). Every change is
 * one Lua script, so two workers can never both win a probe or both take the last token. The scripts read the server clock. Keys share a
 * hash tag, so they live on one slot. A Valkey error fails open: the call is admitted and `connector.governor.unavailable` is logged with
 * the Workspace and the exception class only.
 *
 * Breaker (hash): `state` (`open`), `failures`, `open_until` (ms), `probe_until` (ms, the half-open lease, which lasts one cool-down).
 * Penalty (string): the ms until which no call goes out. Bucket (hash): `tokens` and `ts`, capacity = calls per minute, refilled by rate/60 per
 * second, starting full. In-flight (counter): the calls out now, with a lease TTL when one is configured.
 */
final class ValkeySourceGovernor implements SourceGovernor
{
    public const CONNECTION = 'queue';

    /** Housekeeping TTL of the breaker and penalty keys (the noeviction store never evicts them): state lost after this only re-closes the breaker. */
    private const KEY_TTL_MS = 604_800_000;

    /** A bucket is full again a minute after its last write, so two minutes of idleness lose nothing. */
    private const BUCKET_TTL_MS = 120_000;

    private const ADMIT = <<<'LUA'
        local t = redis.call('TIME')
        local now = t[1] * 1000 + math.floor(t[2] / 1000)
        local cool = tonumber(ARGV[2])
        local probe = 0

        if tonumber(ARGV[1]) > 0 then
            local b = redis.call('HMGET', KEYS[1], 'state', 'open_until', 'probe_until')
            if b[1] == 'open' then
                local open_until = tonumber(b[2]) or 0
                local probe_until = tonumber(b[3]) or 0
                if now < open_until then return {1, open_until - now, 0} end
                if probe_until > now then return {1, probe_until - now, 0} end
                probe = 1
            end
        end

        local pen = tonumber(redis.call('GET', KEYS[2]))
        if pen and pen > now then return {2, pen - now, 0} end

        local rate = tonumber(ARGV[3])
        local tokens = 0
        if rate > 0 then
            local h = redis.call('HMGET', KEYS[3], 'tokens', 'ts')
            tokens = tonumber(h[1])
            local ts = tonumber(h[2])
            if tokens == nil or ts == nil then
                tokens = rate
            else
                tokens = math.min(rate, tokens + math.max(0, now - ts) * rate / 60000)
            end
            if tokens < 1 then return {3, math.ceil((1 - tokens) * 60000 / rate), 0} end
        end

        local conc = tonumber(ARGV[4])
        if conc > 0 then
            local n = tonumber(redis.call('GET', KEYS[4])) or 0
            if n >= conc then return {4, 0, 0} end
        end

        if rate > 0 then
            redis.call('HSET', KEYS[3], 'tokens', tostring(tokens - 1), 'ts', now)
            redis.call('PEXPIRE', KEYS[3], tonumber(ARGV[6]))
        end
        if conc > 0 then
            redis.call('INCR', KEYS[4])
            -- The lease starts when the counter has none and is never pushed out by later admits, so a leaked slot expires under steady load too.
            local lease = tonumber(ARGV[5])
            if lease > 0 and redis.call('PTTL', KEYS[4]) < 0 then redis.call('PEXPIRE', KEYS[4], lease) end
        end
        if probe == 1 then
            redis.call('HSET', KEYS[1], 'probe_until', now + cool)
            redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[7]))
        end
        return {0, 0, probe}
        LUA;

    private const RECORD = <<<'LUA'
        local t = redis.call('TIME')
        local now = t[1] * 1000 + math.floor(t[2] / 1000)
        local outcome = ARGV[1]
        local fc = tonumber(ARGV[2])
        local cool = tonumber(ARGV[3])
        local probe = tonumber(ARGV[4])
        if fc <= 0 then return 0 end

        local state = redis.call('HGET', KEYS[1], 'state')

        if outcome == 'ok' then
            -- Only the probe may close an open breaker: a call admitted before it opened and answering late proves nothing about now.
            if state == 'open' then
                if probe == 1 then
                    redis.call('DEL', KEYS[1])
                    return 2
                end
                return 0
            end
            redis.call('DEL', KEYS[1])
            return 0
        end

        if outcome == 'throttled' then
            if probe == 1 and state == 'open' then redis.call('HSET', KEYS[1], 'probe_until', 0) end
            return 0
        end

        if state == 'open' then
            if probe == 1 then
                redis.call('HSET', KEYS[1], 'open_until', now + cool, 'probe_until', 0)
                return 1
            end
            return 0
        end

        local f = redis.call('HINCRBY', KEYS[1], 'failures', 1)
        if f >= fc then
            redis.call('HSET', KEYS[1], 'state', 'open', 'open_until', now + cool, 'probe_until', 0)
            redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[5]))
            return 1
        end
        redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[5]))
        return 0
        LUA;

    /** Read only: 0 closed, 1 open (inside the cool-down), 2 half-open. */
    private const STATE = <<<'LUA'
        local t = redis.call('TIME')
        local now = t[1] * 1000 + math.floor(t[2] / 1000)
        local b = redis.call('HMGET', KEYS[1], 'state', 'open_until')
        if b[1] ~= 'open' then return 0 end
        if now < (tonumber(b[2]) or 0) then return 1 end
        return 2
        LUA;

    private const PENALIZE = <<<'LUA'
        local t = redis.call('TIME')
        local now = t[1] * 1000 + math.floor(t[2] / 1000)
        local ms = tonumber(ARGV[1]) * 1000
        local pen_until = now + ms
        local cur = tonumber(redis.call('GET', KEYS[1])) or 0
        if ms > 0 and pen_until > cur then redis.call('SET', KEYS[1], pen_until, 'PX', ms + 1000) end
        if tonumber(ARGV[2]) > 0 then
            redis.call('HSET', KEYS[2], 'tokens', '0', 'ts', now)
            redis.call('PEXPIRE', KEYS[2], tonumber(ARGV[3]))
        end
        return 1
        LUA;

    private const RELEASE = <<<'LUA'
        local n = tonumber(redis.call('GET', KEYS[1])) or 0
        if n > 0 then redis.call('DECR', KEYS[1]) end
        return 1
        LUA;

    public function __construct(private readonly Factory $redis) {}

    public function admit(string $workspaceId, string $dataSourceId, GovernorLimits $limits): Admission
    {
        if (! $limits->active()) {
            // Nothing is configured, so nothing needs the store.
            return Admission::admitted();
        }

        try {
            $k = $this->keys($workspaceId, $dataSourceId);
            $reply = $this->run(self::ADMIT, [$k['breaker'], $k['penalty'], $k['bucket'], $k['inflight']], [
                $limits->breakerActive() ? $limits->failureCount : 0,
                ($limits->coolDownSeconds ?? 0) * 1000,
                $limits->ratePerMinute ?? 0,
                $limits->concurrency ?? 0,
                ($limits->leaseSeconds ?? 0) * 1000,
                self::BUCKET_TTL_MS,
                self::KEY_TTL_MS,
            ]);
        } catch (Throwable $e) {
            return $this->unavailable($workspaceId, $e, Admission::admitted());
        }

        $code = (int) ($reply[0] ?? 0);
        $waitSeconds = (int) ceil(((int) ($reply[1] ?? 0)) / 1000);

        return match ($code) {
            0 => Admission::admitted(((int) ($reply[2] ?? 0)) === 1, $limits->concurrency !== null),
            1 => Admission::denied(Admission::CIRCUIT_OPEN, $waitSeconds),
            2, 3 => Admission::denied(Admission::RATE_LIMITED, $waitSeconds),
            default => Admission::denied(Admission::CONCURRENCY, 0),
        };
    }

    public function record(string $workspaceId, string $dataSourceId, CallOutcome $outcome, GovernorLimits $limits, bool $probe = false): ?string
    {
        if (! $limits->breakerActive()) {
            return null;
        }

        try {
            $k = $this->keys($workspaceId, $dataSourceId);
            $reply = (int) $this->run(self::RECORD, [$k['breaker']], [
                $outcome->value, $limits->failureCount, ($limits->coolDownSeconds ?? 0) * 1000, $probe ? 1 : 0, self::KEY_TTL_MS,
            ]);
        } catch (Throwable $e) {
            return $this->unavailable($workspaceId, $e, null);
        }

        return match ($reply) {
            1 => self::OPENED,
            2 => self::CLOSED,
            default => null,
        };
    }

    public function state(string $workspaceId, string $dataSourceId, ?GovernorLimits $limits = null): string
    {
        if ($limits !== null && ! $limits->breakerActive()) {
            return self::STATE_CLOSED;
        }

        try {
            $reply = (int) $this->run(self::STATE, [$this->keys($workspaceId, $dataSourceId)['breaker']], []);
        } catch (Throwable $e) {
            return $this->unavailable($workspaceId, $e, self::STATE_CLOSED);
        }

        return match ($reply) {
            1 => self::STATE_OPEN,
            2 => self::STATE_HALF_OPEN,
            default => self::STATE_CLOSED,
        };
    }

    public function penalize(string $workspaceId, string $dataSourceId, int $seconds, GovernorLimits $limits): void
    {
        if (! $limits->active()) {
            return;
        }

        try {
            $k = $this->keys($workspaceId, $dataSourceId);
            $this->run(self::PENALIZE, [$k['penalty'], $k['bucket']], [max(0, $seconds), $limits->ratePerMinute ?? 0, self::BUCKET_TTL_MS]);
        } catch (Throwable $e) {
            $this->unavailable($workspaceId, $e, null);
        }
    }

    public function release(string $workspaceId, string $dataSourceId): void
    {
        try {
            $this->run(self::RELEASE, [$this->keys($workspaceId, $dataSourceId)['inflight']], []);
        } catch (Throwable $e) {
            $this->unavailable($workspaceId, $e, null);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $args
     */
    private function run(string $script, array $keys, array $args): mixed
    {
        /** @var Connection $connection Laravel's connection takes `eval($script, $numKeys, ...$keysAndArgs)` for every client. */
        $connection = $this->redis->connection(self::CONNECTION);

        // Laravel's own `eval($script, $numKeys, ...$keysAndArgs)`; phpstan reads the underlying \Redis signature from the docblock.
        $arguments = [$script, count($keys), ...$keys, ...$args];

        return $connection->eval(...$arguments); // @phpstan-ignore argument.type
    }

    /** @return array{breaker: string, penalty: string, bucket: string, inflight: string} */
    private function keys(string $workspaceId, string $dataSourceId): array
    {
        $tag = 'sourcegov:{'.strtolower($workspaceId).':'.strtolower($dataSourceId).'}';

        return ['breaker' => $tag.':breaker', 'penalty' => $tag.':penalty', 'bucket' => $tag.':bucket', 'inflight' => $tag.':inflight'];
    }

    /**
     * @template T
     *
     * @param  T  $open  what the caller gets: the store is unavailable, so the call is admitted
     * @return T
     */
    private function unavailable(string $workspaceId, Throwable $e, mixed $open): mixed
    {
        Log::warning('connector.governor.unavailable', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

        return $open;
    }
}
