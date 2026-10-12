<?php

namespace App\Modules\Connector\Contracts;

/** A fetch of an Endpoint that has been checked and counted (Story 3.9): the Endpoint's revision at that moment and every value to send. The values stay in the job. */
final readonly class PreparedSampleFetch
{
    /** @param  array<string, string>  $values  parameter name (`header:{name}` for a header) => value */
    public function __construct(
        public string $dataSourceId,
        public string $endpointId,
        public int $endpointRevision,
        public array $values,
    ) {}
}
