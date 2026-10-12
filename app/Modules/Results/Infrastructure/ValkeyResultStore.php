<?php

namespace App\Modules\Results\Infrastructure;

use App\Modules\Results\Contracts\BlockResult;
use App\Modules\Results\Contracts\ResultSettingNotConfigured;
use App\Modules\Results\Contracts\ResultStore;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;
use Closure;
use Throwable;

/**
 * {@see ResultStore} on the Valkey `cache` store. One hash per key, `ws` (the Workspace the entry was written for, checked on every read like
 * `TenantCache`), `seq` (`payload_seq`) and `body` (the DTO, written and read by the lossless codec, never `json_decode`). The compare-and-set and
 * the TTL are one Lua script, so a write is atomic and no key lives without an expiry.
 */
final class ValkeyResultStore implements ResultStore
{
    public const SETTING = 'DASHFLOW_RESULT_TTL';

    /** Keeps the entry unless it holds a higher `payload_seq`; always sets the expiry. Returns 1 when written, 0 when kept. */
    public const PUT_SCRIPT = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], 'seq')
        if current and tonumber(current) > tonumber(ARGV[1]) then
            return 0
        end
        redis.call('HSET', KEYS[1], 'ws', ARGV[2], 'seq', ARGV[1], 'body', ARGV[3])
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
        return 1
        LUA;

    /** @param  Closure(): ?int  $ttl  seconds a result lives; null while the setting is unset */
    public function __construct(private readonly ValkeyCommands $commands, private readonly Closure $ttl) {}

    public function get(string $workspaceId, string $key): ?BlockResult
    {
        $entry = $this->entry($workspaceId, $key);

        return $entry === null ? null : $entry['result'];
    }

    public function payloadSeq(string $workspaceId, string $key): ?int
    {
        $fields = $this->commands->hgetall($key);

        if (($fields['ws'] ?? null) !== strtolower($workspaceId) || ! isset($fields['seq']) || preg_match('/\A[0-9]{1,18}\z/D', $fields['seq']) !== 1) {
            return null;
        }

        return (int) $fields['seq'];
    }

    public function put(string $workspaceId, string $key, BlockResult $result): bool
    {
        $ttl = ($this->ttl)() ?? throw new ResultSettingNotConfigured(self::SETTING);

        $applied = $this->commands->eval(
            self::PUT_SCRIPT,
            [$key],
            [(string) $result->payloadSeq, strtolower($workspaceId), LosslessJson::encode(self::toObjects($result->toArray())), (string) $ttl],
        );

        return (int) $applied === 1;
    }

    /** @return array{result: BlockResult}|null */
    private function entry(string $workspaceId, string $key): ?array
    {
        $fields = $this->commands->hgetall($key);

        // Another Workspace's entry, or one this code cannot read (an older DTO version): a miss.
        if (($fields['ws'] ?? null) !== strtolower($workspaceId) || ! isset($fields['body'])) {
            return null;
        }

        try {
            $plain = self::plain(LosslessJson::decode($fields['body']));
        } catch (Throwable) {
            return null;
        }

        $result = is_array($plain) ? BlockResult::fromArray($plain) : null;

        return $result === null ? null : ['result' => $result];
    }

    private static function toObjects(mixed $value): mixed
    {
        if (is_int($value)) {
            return new DecimalLiteral((string) $value);
        }

        if (is_array($value) && $value !== [] && array_is_list($value)) {
            return array_map(self::toObjects(...), $value);
        }

        return is_array($value) ? new JsonObject(array_map(self::toObjects(...), $value)) : $value;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $out = [];

            foreach ($value->entries() as [$key, $inner]) {
                $out[$key] = self::plain($inner);
            }

            return $out;
        }

        if ($value instanceof DecimalLiteral) {
            return $value->isInteger() && strlen($value->lexeme) <= 15 ? (int) $value->lexeme : $value->lexeme;
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }
}
