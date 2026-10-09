<?php

namespace App\Modules\Connector\Contracts;

/**
 * How a Data Source's API pages its answers (Story 2.11): the style and what the style needs. Names and paths only, never a
 * value. `none` is one request. `page` and `offset` send `param` (a page number from 1, an offset from 0) until a page has no
 * records; `cursor` sends the cursor read at `cursorPath` as the value of `param` until there is none; `link_header` follows
 * the `rel="next"` link of the `Link` header. `recordsPath` is where the records array sits in each page (null: the response
 * root); `sizeParam` and `size` are sent with every page request when set.
 */
final readonly class Pagination
{
    public const STYLES = ['none', 'page', 'offset', 'cursor', 'link_header'];

    public function __construct(
        public string $style = 'none',
        public ?string $param = null,
        public ?string $sizeParam = null,
        public ?int $size = null,
        public ?string $recordsPath = null,
        public ?string $cursorPath = null,
    ) {}

    /** Whether more than one request may be made. */
    public function enabled(): bool
    {
        return in_array($this->style, ['page', 'offset', 'cursor', 'link_header'], true);
    }

    /** Whether the style sends a parameter that carries the page, the offset or the cursor. */
    public static function needsParam(string $style): bool
    {
        return in_array($style, ['page', 'offset', 'cursor'], true);
    }

    /** @return array<string, mixed> names and numbers only */
    public function toArray(): array
    {
        return [
            'style' => $this->style,
            'param' => $this->param,
            'size_param' => $this->sizeParam,
            'size' => $this->size,
            'records_path' => $this->recordsPath,
            'cursor_path' => $this->cursorPath,
        ];
    }
}
