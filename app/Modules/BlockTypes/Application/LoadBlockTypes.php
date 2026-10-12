<?php

namespace App\Modules\BlockTypes\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;
use App\Modules\BlockTypes\Contracts\BlockTypeVocabulary;
use App\Modules\BlockTypes\Contracts\InvalidBlockTypePackage;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use JsonException;

/**
 * Loads and validates every `{root}/{key}/v{N}/` package. All packages are validated before any is registered, so one invalid package
 * registers none. `schema.json` is configuration (not a payload body), so PHP's decoder is fine here.
 */
final class LoadBlockTypes implements BlockTypeRegistry
{
    /** @var array<string, BlockType>|null "key@version" => package, set only once every package validated */
    private ?array $types = null;

    public function __construct(private readonly string $root) {}

    public function all(): array
    {
        return array_values($this->load());
    }

    public function resolve(string $key, int $contractVersion): BlockType
    {
        return $this->load()["{$key}@{$contractVersion}"] ?? throw UnknownBlockType::pair($key, $contractVersion);
    }

    /** @return array<string, BlockType> */
    private function load(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $found = [];
        $dirs = glob(rtrim($this->root, '/').'/*/v*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            if (preg_match('/\Av[1-9][0-9]*\z/', basename($dir)) !== 1) {
                continue;
            }

            $type = $this->package($dir);
            $found["{$type->key}@{$type->contractVersion}"] = $type;
        }

        return $this->types = $found;
    }

    private function package(string $dir): BlockType
    {
        $version = basename($dir);
        $name = basename(dirname($dir)).'/'.$version;

        foreach (['schema.json', 'Shaper.php', 'Renderer.vue'] as $file) {
            if (! is_file("{$dir}/{$file}")) {
                throw InvalidBlockTypePackage::in($name, "{$file} is missing");
            }
        }

        try {
            $schema = json_decode((string) file_get_contents("{$dir}/schema.json"), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidBlockTypePackage::in($name, 'schema.json is not valid JSON ('.$e->getMessage().')');
        }

        if (! is_array($schema)) {
            throw InvalidBlockTypePackage::in($name, 'schema.json must be an object');
        }

        $dirKey = basename(dirname($dir));
        if (preg_match('/\A[a-z][a-z0-9]*(-[a-z0-9]+)*\z/', $dirKey) !== 1) {
            throw InvalidBlockTypePackage::in($name, 'the package key must be lowercase kebab-case (letters, digits and single hyphens, starting with a letter)');
        }

        $key = $schema['key'] ?? null;
        if (! is_string($key) || $key !== basename(dirname($dir))) {
            throw InvalidBlockTypePackage::in($name, 'key must equal the package directory name');
        }

        $contractVersion = $schema['contractVersion'] ?? null;
        if (! is_int($contractVersion) || $version !== 'v'.$contractVersion) {
            throw InvalidBlockTypePackage::in($name, 'contractVersion must equal the version directory');
        }

        $title = $schema['title'] ?? null;
        if (! is_string($title) || $title === '') {
            throw InvalidBlockTypePackage::in($name, 'title is required');
        }

        $slots = $this->slots($name, $schema['slots'] ?? null);
        $size = $this->size($name, $schema['size'] ?? null);
        $aggregations = $this->aggregations($name, $schema['defaultAggregations'] ?? [], $slots);

        return new BlockType($key, $contractVersion, $title, $slots, $size, $aggregations);
    }

    /** @return list<BlockTypeSlot> */
    private function slots(string $package, mixed $raw): array
    {
        if (! is_array($raw) || $raw === [] || ! array_is_list($raw)) {
            throw InvalidBlockTypePackage::in($package, 'slots must be a non-empty list');
        }

        $slots = [];

        foreach ($raw as $index => $slot) {
            $key = is_array($slot) && is_string($slot['key'] ?? null) && preg_match('/\A[a-z][a-z0-9_]*\z/', $slot['key']) === 1 ? $slot['key'] : null;

            if ($key === null) {
                throw InvalidBlockTypePackage::in($package, 'a slot needs a snake_case key', "#{$index}");
            }

            if (isset($slots[$key])) {
                throw InvalidBlockTypePackage::in($package, 'duplicate slot key', $key);
            }

            $label = $slot['label'] ?? null;
            if (! is_string($label) || $label === '') {
                throw InvalidBlockTypePackage::in($package, 'label is required', $key);
            }

            if (! is_bool($slot['required'] ?? null)) {
                throw InvalidBlockTypePackage::in($package, 'required must be true or false', $key);
            }

            $valueType = $slot['valueType'] ?? null;
            if (! is_string($valueType) || ! in_array($valueType, BlockTypeVocabulary::VALUE_TYPES, true)) {
                throw InvalidBlockTypePackage::in($package, 'unknown value type '.json_encode($valueType).' (expected '.implode(', ', BlockTypeVocabulary::VALUE_TYPES).')', $key);
            }

            $cardinality = $slot['cardinality'] ?? null;
            if (! is_string($cardinality)) {
                throw InvalidBlockTypePackage::in($package, 'cardinality is missing', $key);
            }
            if (! in_array($cardinality, BlockTypeVocabulary::CARDINALITIES, true)) {
                throw InvalidBlockTypePackage::in($package, "unknown cardinality {$cardinality} (expected ".implode(', ', BlockTypeVocabulary::CARDINALITIES).')', $key);
            }

            $roles = $slot['fieldRoles'] ?? null;
            if (! is_array($roles) || $roles === [] || ! array_is_list($roles)) {
                throw InvalidBlockTypePackage::in($package, 'fieldRoles must be a non-empty list', $key);
            }
            foreach ($roles as $role) {
                if (! is_string($role) || ! in_array($role, BlockTypeVocabulary::FIELD_ROLES, true)) {
                    throw InvalidBlockTypePackage::in($package, 'unknown field role '.json_encode($role), $key);
                }
            }

            $slots[$key] = new BlockTypeSlot($key, $label, $slot['required'], $valueType, $cardinality, $roles);
        }

        return array_values($slots);
    }

    /** @return array{default_width: int, min_width: int, max_width: int, default_height: string, min_height: string, max_height: string} */
    private function size(string $package, mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        foreach (['defaultWidth', 'minWidth', 'maxWidth'] as $field) {
            if (! is_int($raw[$field] ?? null) || $raw[$field] < 1) {
                throw InvalidBlockTypePackage::in($package, "size.{$field} must be a positive integer");
            }
        }

        foreach (['defaultHeight', 'minHeight', 'maxHeight'] as $field) {
            if (! is_string($raw[$field] ?? null) || ! in_array($raw[$field], BlockTypeVocabulary::HEIGHTS, true)) {
                throw InvalidBlockTypePackage::in($package, "size.{$field} must be one of ".implode(', ', BlockTypeVocabulary::HEIGHTS));
            }
        }

        if ($raw['minWidth'] > $raw['defaultWidth'] || $raw['defaultWidth'] > $raw['maxWidth']) {
            throw InvalidBlockTypePackage::in($package, 'size must satisfy minWidth <= defaultWidth <= maxWidth');
        }

        $rank = array_flip(BlockTypeVocabulary::HEIGHTS);
        if ($rank[$raw['minHeight']] > $rank[$raw['defaultHeight']] || $rank[$raw['defaultHeight']] > $rank[$raw['maxHeight']]) {
            throw InvalidBlockTypePackage::in($package, 'size must satisfy minHeight <= defaultHeight <= maxHeight');
        }

        return [
            'default_width' => $raw['defaultWidth'],
            'min_width' => $raw['minWidth'],
            'max_width' => $raw['maxWidth'],
            'default_height' => $raw['defaultHeight'],
            'min_height' => $raw['minHeight'],
            'max_height' => $raw['maxHeight'],
        ];
    }

    /**
     * @param  list<BlockTypeSlot>  $slots
     * @return array<string, string>
     */
    private function aggregations(string $package, mixed $raw, array $slots): array
    {
        if (! is_array($raw)) {
            throw InvalidBlockTypePackage::in($package, 'defaultAggregations must be an object');
        }

        $types = [];
        foreach ($slots as $slot) {
            $types[$slot->key] = $slot->valueType;
        }

        $out = [];
        foreach ($raw as $slot => $aggregation) {
            $slot = (string) $slot;

            if (! isset($types[$slot])) {
                throw InvalidBlockTypePackage::in($package, 'default aggregation for an undeclared slot', $slot);
            }
            if ($types[$slot] !== 'number') {
                throw InvalidBlockTypePackage::in($package, 'default aggregations apply to number slots only', $slot);
            }
            if (! is_string($aggregation) || ! in_array($aggregation, BlockTypeVocabulary::AGGREGATIONS, true)) {
                throw InvalidBlockTypePackage::in($package, 'unknown aggregation '.json_encode($aggregation), $slot);
            }

            $out[$slot] = $aggregation;
        }

        return $out;
    }
}
