<?php

namespace App\Platform\Operations;

use InvalidArgumentException;

/** The registry of Operation kinds. Modules register theirs when the application boots; the kernel only looks them up. */
final class OperationKinds
{
    /** @var array<string, OperationKind> */
    private array $kinds = [];

    public function register(OperationKind $kind): void
    {
        if (isset($this->kinds[$kind->name])) {
            throw new InvalidArgumentException("The Operation kind {$kind->name} is already registered.");
        }

        $this->kinds[$kind->name] = $kind;
    }

    public function has(string $name): bool
    {
        return isset($this->kinds[$name]);
    }

    public function get(string $name): OperationKind
    {
        return $this->kinds[$name] ?? throw new InvalidArgumentException("The Operation kind {$name} is not registered.");
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->kinds);
    }
}
