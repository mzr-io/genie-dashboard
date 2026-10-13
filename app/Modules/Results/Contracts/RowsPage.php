<?php

namespace App\Modules\Results\Contracts;

/**
 * One page of a Block's rows (Story 5.9). `status` is `ok`, `empty` (the Block has no rows to browse), `expired` (the set left the cache: retry, a
 * compute is queued and no source is called) or `unavailable` (no result yet, or the Block is not readable). Rows are values only, in `columns` order.
 */
final readonly class RowsPage
{
    public const OK = 'ok';

    public const EMPTY = 'empty';

    public const EXPIRED = 'expired';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<list<string|bool|null>>  $rows
     */
    public function __construct(
        public string $status,
        public array $columns = [],
        public array $rows = [],
        public int $total = 0,
        public int $page = 1,
        public int $pageSize = 25,
        public int $pages = 0,
        /** The page asked for was past the last one: this is the last page. */
        public bool $clamped = false,
        public ?string $dataAsOf = null,
        public ?string $resultEtag = null,
    ) {}
}
