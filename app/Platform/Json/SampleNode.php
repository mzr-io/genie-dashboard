<?php

namespace App\Platform\Json;

/** One distinct path of a sample while {@see SampleShape} builds it: the types seen there, an example and its children. */
final class SampleNode
{
    /** @var array<string, true> */
    public array $types = [];

    public ?string $example = null;

    public ?int $count = null;

    /** @var array<string, SampleNode> keyed `m:{name}` for a member and `*` for the elements of an array */
    public array $children = [];

    /** @var list<mixed> the first elements of an array seen here, for the table view */
    public array $rows = [];

    public int $total = 0;

    /** @param  string|null  $segment  the member name, or null for an array element (and the root) */
    public function __construct(public readonly ?string $segment = null) {}

    public function member(string $name): self
    {
        return $this->children['m:'.$name] ??= new self($name);
    }

    public function element(): self
    {
        return $this->children['*'] ??= new self;
    }
}
