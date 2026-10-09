<?php

namespace App\Platform\EditLock;

use InvalidArgumentException;

/** The lockable resource types and the module code that keeps each one's epoch. */
final class EditLockResources
{
    /** @var array<string, LockEpochs> */
    private array $epochs = [];

    public function register(string $type, LockEpochs $epochs): void
    {
        self::assertType($type);
        $this->epochs[$type] = $epochs;
    }

    public function for(string $type): LockEpochs
    {
        return $this->epochs[$type] ?? throw new EditLockResourceMissing;
    }

    public static function assertType(string $type): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,25}\z/D', $type) !== 1) {
            throw new InvalidArgumentException('A lock resource type must be a lowercase snake_case name.');
        }
    }
}
