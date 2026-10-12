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
        /**
         * What "About this block" says (Story 4.6), all of it User-safe: the description, the Data Source's name, the refresh interval, the version
         * label and how the period is followed.
         *
         * @var array{description: string|null, source_name: string|null, refresh_interval: int|string|null, version: string, period: string|null}
         */
        public array $about,
    ) {}
}
