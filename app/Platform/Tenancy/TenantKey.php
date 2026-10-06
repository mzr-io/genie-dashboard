<?php

namespace App\Platform\Tenancy;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one place that builds cache keys, locks, queue names, object keys and channel names.
 * Every one starts with the Workspace ID.
 */
final class TenantKey
{
    public static function cache(string $workspaceId, string $key): string
    {
        return self::workspace($workspaceId).':cache:'.self::part($key);
    }

    public static function lock(string $workspaceId, string $name): string
    {
        return self::workspace($workspaceId).':lock:'.self::part($name);
    }

    public static function queue(string $workspaceId, string $queue): string
    {
        return self::workspace($workspaceId).':queue:'.self::part($queue);
    }

    public static function object(string $workspaceId, string $path): string
    {
        return self::workspace($workspaceId).'/'.self::part($path);
    }

    public static function channel(string $workspaceId, string $channel): string
    {
        return self::workspace($workspaceId).'.'.self::part($channel);
    }

    /** The canonical (lower-case) Workspace ID, or an exception: a malformed ID never builds a key. */
    public static function workspace(string $workspaceId): string
    {
        if (! Str::isUuid($workspaceId)) {
            throw new InvalidArgumentException('A Workspace ID must be a UUID.');
        }

        return strtolower($workspaceId);
    }

    /**
     * A key part cannot climb out of the Workspace prefix: no control characters, no `..` segment,
     * and no leading delimiter (`/`, `\\`, `:` or `.`).
     */
    private static function part(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('A tenant key part must not be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || in_array($value[0], ['/', '\\', ':', '.'], true)
            || in_array('..', preg_split('#[/\\\\]#', $value) ?: [], true)) {
            throw new InvalidArgumentException('A tenant key part must not contain control characters, a ".." segment or a leading delimiter.');
        }

        return $value;
    }
}
