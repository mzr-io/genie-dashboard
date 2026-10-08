<?php

namespace App\Modules\Connector\Contracts;

/** The Endpoints of a Data Source that match a search, with the counts the list announces. */
final readonly class EndpointPage
{
    /**
     * @param  list<Endpoint>  $rows
     * @param  int  $total  every Endpoint of the Data Source
     * @param  int  $matched  the Endpoints the search matches
     */
    public function __construct(public array $rows, public int $total, public int $matched) {}
}
