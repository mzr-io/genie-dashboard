<?php

namespace App\Modules\Dashboards\Contracts;

/**
 * The outcome of `AddItem` or `RemoveItem` (Story 4.9): the dashboard's revision after the command, whether it changed anything (a repeated command
 * does not), and for an add the item as the layout returns it and where the next default-sized Block would go.
 */
final readonly class ItemChange
{
    /**
     * @param  array<string, mixed>|null  $item
     * @param  array{col: int, row: int}|null  $nextFreeSlot
     */
    public function __construct(
        public int $revision,
        public bool $changed,
        public ?array $item = null,
        public ?array $nextFreeSlot = null,
    ) {}
}
