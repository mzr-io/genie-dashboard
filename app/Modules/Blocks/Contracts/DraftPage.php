<?php

namespace App\Modules\Blocks\Contracts;

/** The Drafts of the Workspace that match a search, with the counts the list announces. */
final readonly class DraftPage
{
    /**
     * @param  list<Draft>  $rows
     * @param  int  $total  every Draft of the Workspace
     * @param  int  $matched  the Drafts the search matches
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $matched,
    ) {}
}
