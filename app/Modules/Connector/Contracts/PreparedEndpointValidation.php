<?php

namespace App\Modules\Connector\Contracts;

/**
 * An Endpoint validation that has been checked and counted (Story 3.18): the Endpoint's revision at that moment, every value to send that is not
 * bound to a member, and whether the Endpoint is bound to the member's own context. The values stay in the job.
 */
final readonly class PreparedEndpointValidation
{
    /**
     * @param  array<string, string>  $values  parameter name (`header:{name}` for a header) => value
     * @param  array<string, string>|null  $comparisonValues  the same for the prior window of a prior-period comparison (Story 5.6b), null when there is none
     */
    public function __construct(
        public string $dataSourceId,
        public string $endpointId,
        public int $endpointRevision,
        public array $values,
        public bool $userBound,
        public ?array $comparisonValues = null,
    ) {}
}
