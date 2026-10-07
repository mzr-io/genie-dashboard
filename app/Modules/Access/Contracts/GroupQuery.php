<?php

namespace App\Modules\Access\Contracts;

/** What the group list asks for: a search over names, a sort and its direction. */
final readonly class GroupQuery
{
    public function __construct(
        public ?string $search = null,
        public GroupSort $sort = GroupSort::Name,
        public bool $descending = false,
    ) {}
}
