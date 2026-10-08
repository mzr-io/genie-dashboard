<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;

/**
 * A dotted path into a decoded JSON document (Story 2.11): object keys and array indexes, such as `data.items` or
 * `meta.next` or `pages.0.rows`. A segment uses `[A-Za-z0-9_-]`, at most {@see self::MAX_SEGMENTS} segments. The empty path is
 * the document itself. Read and replace work on the tree {@see LosslessJson::decode()} builds.
 */
final class PaginationPath
{
    public const MAX_SEGMENTS = 8;

    public const MAX_LENGTH = 255;

    public static function valid(string $path): bool
    {
        return strlen($path) <= self::MAX_LENGTH
            && preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,'.(self::MAX_SEGMENTS - 1).'}\z/D', $path) === 1;
    }

    /** @return list<string> */
    public static function segments(?string $path): array
    {
        return $path === null || $path === '' ? [] : explode('.', $path);
    }

    /**
     * The value at the path.
     *
     * @return array{0: bool, 1: mixed} whether the path leads somewhere, and the value there
     */
    public static function find(mixed $document, ?string $path): array
    {
        $node = $document;

        foreach (self::segments($path) as $segment) {
            if ($node instanceof JsonObject) {
                if (! $node->has($segment)) {
                    return [false, null];
                }

                $node = $node->get($segment);
            } elseif (is_array($node) && array_is_list($node) && ctype_digit($segment) && array_key_exists((int) $segment, $node)) {
                $node = $node[(int) $segment];
            } else {
                return [false, null];
            }
        }

        return [true, $node];
    }

    /**
     * The document with the value at the path replaced; every other member is kept as it is. The path must lead somewhere.
     *
     * @param  list<string>|null  $segments
     */
    public static function replace(mixed $document, ?string $path, mixed $value, ?array $segments = null): mixed
    {
        $segments ??= self::segments($path);

        if ($segments === []) {
            return $value;
        }

        $segment = array_shift($segments);

        if ($document instanceof JsonObject) {
            $members = [];

            foreach ($document->entries() as [$key, $member]) {
                $members[$key] = $key === $segment ? self::replace($member, null, $value, $segments) : $member;
            }

            return new JsonObject($members);
        }

        if (is_array($document) && array_is_list($document) && ctype_digit($segment) && array_key_exists((int) $segment, $document)) {
            $document[(int) $segment] = self::replace($document[(int) $segment], null, $value, $segments);

            return $document;
        }

        throw new \InvalidArgumentException('The path does not lead into the document.');
    }
}
