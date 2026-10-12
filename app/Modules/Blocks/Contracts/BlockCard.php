<?php

namespace App\Modules\Blocks\Contracts;

/**
 * What a dashboard may show of a Block (Story 4.4): User-safe, so it names no Data Source, Endpoint, Query Plan or sample. `versionId` is the
 * current published version; a Block without one has no card (it is `unpublished`).
 */
final readonly class BlockCard
{
    /**
     * @param  array{show_header: bool, show_subtitle: bool, allow_resize: bool, allow_minimize: bool, allow_refresh: bool, show_footer_action: bool}  $chrome
     * @param  array{min_width: int, max_width: int, width: int, min_height: string, max_height: string, height: string}  $sizes
     */
    public function __construct(
        public string $blockId,
        public string $versionId,
        public string $name,
        public ?string $subtitle,
        public string $type,
        public ?string $icon,
        public array $chrome,
        public array $sizes,
        public int|string|null $refreshInterval,
    ) {}
}
