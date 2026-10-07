<?php

namespace App\Platform\Audit;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The per-module allowlist registry. A module registers its AuditSerializer at boot; serializing an
 * action of a module without one throws, so nothing is stored without an allowlist.
 */
final class AuditSerializers
{
    /** Short enum-style slug stored as is. */
    public const SLUG = '/\A[a-z][a-z0-9_.-]{0,47}\z/D';

    /** @var array<string, AuditSerializer> */
    private array $serializers = [];

    public function __construct(private readonly AuditHasher $hasher) {}

    public function register(AuditSerializer $serializer): void
    {
        $module = $serializer->module();

        if (isset($this->serializers[$module])) {
            throw new InvalidArgumentException("An audit serializer for module {$module} is already registered.");
        }

        foreach ($serializer->fields() as $field => $kind) {
            if ($field === '') {
                throw new InvalidArgumentException("Audit serializer for {$module} has an invalid field definition.");
            }
        }

        $this->serializers[$module] = $serializer;
    }

    /**
     * Keeps only the listed fields: IDs and enums plain (and validated as such), all else as a keyed hash.
     * A null value is stored as null.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function serialize(AuditAction $action, array $values): array
    {
        $serializer = $this->serializers[$action->module()]
            ?? throw new InvalidArgumentException("No audit serializer is registered for module {$action->module()}.");

        $fields = $serializer->fields();
        $stored = [];

        foreach ($fields as $name => $kind) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            $stored[$name] = match (true) {
                $value === null => null,
                $kind === AuditField::Id => $this->id($name, $value),
                $kind === AuditField::Enum => $this->slug($name, $value),
                $kind === AuditField::Count => $this->count($name, $value),
                $kind === AuditField::EnumList => $this->slugs($name, $value),
                default => $this->hasher->hash($value),
            };
        }

        return $stored;
    }

    private function id(string $name, mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && Str::isUuid($value)) {
            return strtolower($value);
        }

        throw new InvalidArgumentException("Audit field {$name} is declared an ID and must be an integer or a UUID.");
    }

    private function count(string $name, mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw new InvalidArgumentException("Audit field {$name} is declared a count and must be a non-negative integer.");
    }

    /**
     * @return list<string>
     */
    private function slugs(string $name, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Audit field {$name} is declared an enum list and must be a list of short lowercase slugs.");
        }

        $slugs = array_map(fn (mixed $item): string => $this->slug($name, $item), $value);
        $slugs = array_values(array_unique($slugs));
        sort($slugs);

        return $slugs;
    }

    private function slug(string $name, mixed $value): string
    {
        if (is_string($value) && preg_match(self::SLUG, $value) === 1) {
            return $value;
        }

        throw new InvalidArgumentException("Audit field {$name} is declared an enum and must be a short lowercase slug.");
    }
}
