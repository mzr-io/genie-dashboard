<?php

namespace App\Modules\BlockTypes\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;

/**
 * The Shapers of the packages under `block-types/`, by pinned `(key, contractVersion)`: never "latest". A package is found by its place, never by a
 * list (Story 5.17): `block-types/{key}/v{N}/Shaper.php` declares its own namespace and a `Shaper` class, and adding that file is all it takes.
 */
final class PackageShapers implements BlockTypeShapers
{
    /** @var array<string, class-string|null> */
    private array $found = [];

    public function __construct(private readonly ?string $root = null) {}

    public function shape(BlockType $type, array $slots, array $options = []): array
    {
        $class = $this->classOf($type->key, $type->contractVersion) ?? throw UnknownBlockType::pair($type->key, $type->contractVersion);

        // A package's Shaper is a plain class with `shape(array $slots, array $options): array`, found by its place.
        $shaper = new $class;

        if (! method_exists($shaper, 'shape')) {
            throw UnknownBlockType::pair($type->key, $type->contractVersion);
        }

        /** @var array<string, mixed> $payload */
        $payload = $shaper->shape($slots, $options);

        return $payload;
    }

    /** @return class-string|null */
    private function classOf(string $key, int $version): ?string
    {
        $at = "{$key}@{$version}";

        if (array_key_exists($at, $this->found)) {
            return $this->found[$at];
        }

        if (preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $key) !== 1 || $version < 1) {
            return $this->found[$at] = null;
        }

        $file = rtrim($this->root ?? dirname(__DIR__, 4).'/block-types', '/')."/{$key}/v{$version}/Shaper.php";
        $source = is_file($file) ? file_get_contents($file) : false;

        if ($source === false || preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $m) !== 1) {
            return $this->found[$at] = null;
        }

        /** @var class-string $class */
        $class = $m[1].'\\Shaper';

        if (! class_exists($class, false)) {
            require_once $file;
        }

        return $this->found[$at] = class_exists($class, false) ? $class : null;
    }
}
