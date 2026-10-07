<?php

namespace App\Modules\Access\Contracts;

/** What the user list asks for: search, sort, page size and the cursor of the page. */
final readonly class MemberQuery
{
    /** The framework's page size, used while the `lists.max_page_size` tunable is unset. */
    public const DEFAULT_PAGE_SIZE = 15;

    public function __construct(
        public ?string $search = null,
        public MemberSort $sort = MemberSort::Name,
        public bool $descending = false,
        public int $pageSize = self::DEFAULT_PAGE_SIZE,
        public ?string $cursor = null,
    ) {}
}
