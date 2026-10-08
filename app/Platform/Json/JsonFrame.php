<?php

namespace App\Platform\Json;

/** A container the decoder is still building: its members so far and, for an object, the key whose value is next. */
final class JsonFrame
{
    /** @var array<int|string, mixed> */
    public array $members = [];

    public ?string $key = null;

    public function __construct(public readonly bool $isObject) {}
}
