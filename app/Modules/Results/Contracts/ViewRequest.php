<?php

namespace App\Modules\Results\Contracts;

/**
 * Somebody is looking at a Block (Story 4.3). The caller (Dashboards) names the version, the member and what the period resolves from: the user's
 * own Block Period Selector `$selection` and the dashboard Date Range `$dashboardRange`, both preset tokens (never dates). `$bound` holds the values
 * resolved for the member for the Endpoint's user-bound rows (by parameter name, `header:{name}` for a header); empty for shared data.
 */
final readonly class ViewRequest
{
    /** @param  array<string, mixed>  $bound */
    public function __construct(
        public string $workspaceId,
        public string $membershipId,
        public string $blockVersionId,
        public ?string $selection = null,
        public ?string $dashboardRange = null,
        public array $bound = [],
        /** @var array{from: string, to: string}|null the dates of the dashboard's stored Custom range, set only when `$dashboardRange` is `custom` */
        public ?array $dashboardCustom = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['block_version_id' => $this->blockVersionId];
    }
}
