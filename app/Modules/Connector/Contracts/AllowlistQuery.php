<?php

namespace App\Modules\Connector\Contracts;

/** What the allowlist list asks for: a search over hosts, a sort and its direction. */
final readonly class AllowlistQuery
{
    public function __construct(
        public ?string $search = null,
        public AllowlistSort $sort = AllowlistSort::Host,
        public bool $descending = false,
    ) {}
}
