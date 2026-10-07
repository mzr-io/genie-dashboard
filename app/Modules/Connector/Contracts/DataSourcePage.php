<?php

namespace App\Modules\Connector\Contracts;

/** The Data Sources of the Workspace that match a query, with the counts the list announces. */
final readonly class DataSourcePage
{
    /**
     * @param  list<DataSource>  $rows
     * @param  int  $total  every Data Source of the Workspace
     * @param  int  $matched  the Data Sources the search matches
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $matched,
    ) {}
}
