<?php

namespace App\Platform\Json;

use Countable;

/**
 * A decoded JSON object: an ordered map that keeps the key order of the body. It exists so an object and an array stay
 * apart (an empty object is not an empty list) and so a key such as "1" is not mistaken for a position. Every key is a string
 * here, whatever PHP does with it inside its own arrays. A duplicate key never gets this far: the decoder refuses it.
 */
final readonly class JsonObject implements Countable
{
    /** @param  array<int|string, mixed>  $members  in body order */
    public function __construct(private array $members = []) {}

    public function count(): int
    {
        return count($this->members);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->members);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->members) ? $this->members[$key] : $default;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->members));
    }

    /** @return list<array{0: string, 1: mixed}> the `[key, value]` pairs in body order */
    public function entries(): array
    {
        $pairs = [];

        foreach ($this->members as $key => $value) {
            $pairs[] = [(string) $key, $value];
        }

        return $pairs;
    }
}
