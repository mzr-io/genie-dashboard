<?php

namespace App\Modules\Access\Contracts;

/** One page of the user list. `total` counts every row of the Workspace, `matched` those the search matches. */
final readonly class MemberPage
{
    /**
     * @param  list<MemberRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public int $total,
        public int $matched,
    ) {}
}
