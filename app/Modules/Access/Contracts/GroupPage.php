<?php

namespace App\Modules\Access\Contracts;

/** The groups of the Workspace that match a query, with the counts the list announces. */
final readonly class GroupPage
{
    /**
     * @param  list<GroupRow>  $rows
     * @param  int  $total  every group of the Workspace
     * @param  int  $matched  the groups the search matches
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $matched,
    ) {}
}
