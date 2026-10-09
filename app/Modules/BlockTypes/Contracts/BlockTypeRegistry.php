<?php

namespace App\Modules\BlockTypes\Contracts;

/**
 * The code-defined registry of Block Types. It holds only packages that exist under `block-types/`, and resolves a pinned
 * `(key, contractVersion)` pair: there is no "latest".
 */
interface BlockTypeRegistry
{
    /**
     * @return list<BlockType> every registered package, ordered by key then contract version
     *
     * @throws InvalidBlockTypePackage when any package is invalid (nothing is registered then)
     */
    public function all(): array;

    /**
     * @throws UnknownBlockType when the pair is not registered; it never falls back to another version
     * @throws InvalidBlockTypePackage
     */
    public function resolve(string $key, int $contractVersion): BlockType;
}
