<?php

namespace App\Modules\Connector\Contracts;

/** The Workspace's allowlist that matches a query, with the list-level revision and the counts the list announces. */
final readonly class AllowlistPage
{
    /**
     * @param  list<HostEntry>  $rows
     * @param  int  $revision  the list's revision: a change is accepted only against the current one
     * @param  int  $total  every entry of the Workspace
     * @param  int  $matched  the entries the search matches
     */
    public function __construct(
        public array $rows,
        public int $revision,
        public int $total,
        public int $matched,
    ) {}
}
