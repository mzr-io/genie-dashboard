<?php

namespace App\Modules\Access\Contracts;

/** A defined key with one member's value (null when none is set). The value is plain text for the Admin who may set it, and goes nowhere else. */
final readonly class AttributeValueRow
{
    public function __construct(
        public string $keyId,
        public string $label,
        public string $valueType,
        #[\SensitiveParameter] public ?string $value,
    ) {}
}
