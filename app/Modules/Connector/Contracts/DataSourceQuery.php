<?php

namespace App\Modules\Connector\Contracts;

/** What the Data source list asks for: a search over names and hosts, a sort and its direction. */
final readonly class DataSourceQuery
{
    public function __construct(
        public ?string $search = null,
        public DataSourceSort $sort = DataSourceSort::Name,
        public bool $descending = false,
    ) {}
}
