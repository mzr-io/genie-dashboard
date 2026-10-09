<?php

namespace App\Modules\BlockTypes\Contracts;

/** One Slot of a Block Type: a value type, a cardinality and the Field Roles it accepts. */
final readonly class BlockTypeSlot
{
    /**
     * @param  list<string>  $fieldRoles
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public string $valueType,
        public string $cardinality,
        public array $fieldRoles,
    ) {}

    /** @return array{key: string, label: string, required: bool, value_type: string, cardinality: string, field_roles: list<string>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'value_type' => $this->valueType,
            'cardinality' => $this->cardinality,
            'field_roles' => $this->fieldRoles,
        ];
    }
}
