<?php

namespace App\Modules\Dashboards\Contracts;

/**
 * A dashboard's layout as `GET /api/v1/dashboards/{id}` returns it (Story 4.4). Each item is
 * `{item_id, block_id, geometry: {col, row, width, height}, minimized, state, refresh_interval, is_live, block}` where `state` is `ok`,
 * `unpublished` or `access_removed` and `block` is the User-safe card (null for the last two: no data, no name).
 *
 * @phpstan-type Item array{item_id: string, block_id: string, geometry: array{col: int, row: int, width: int, height: string}, minimized: bool, state: string, refresh_interval: int|string|null, is_live: bool, block: array<string, mixed>|null}
 */
final readonly class DashboardView
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{col: int, row: int}  $nextFreeSlot
     */
    public function __construct(
        public string $dashboardId,
        public string $name,
        public bool $isOverview,
        public int $revision,
        public array $items,
        public array $nextFreeSlot,
    ) {}
}
