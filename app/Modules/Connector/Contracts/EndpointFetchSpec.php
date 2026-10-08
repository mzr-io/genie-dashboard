<?php

namespace App\Modules\Connector\Contracts;

/**
 * What a scheduled fetch asks Connector to call (Story 2.14): one Endpoint revision of one Data Source revision, with the parameter
 * values of the sync target (`header:{name}` for a header). The values are Admin configuration, kept out of every log and record.
 */
final readonly class EndpointFetchSpec
{
    /** @param  array<string, string>  $values  parameter name => value */
    public function __construct(
        public string $workspaceId,
        public string $dataSourceId,
        public string $endpointId,
        public string $endpointRevisionId,
        public int $dataSourceRevision,
        public array $values,
        /** The run's id: sent as the `Idempotency-Key` of a POST. */
        public ?string $runId = null,
        /** Story 2.15: the stored `ETag`, sent verbatim as `If-None-Match`. Wins over `ifModifiedSince`. Ignored for a paged Data Source. */
        public ?string $ifNoneMatch = null,
        /** Story 2.15: the stored `Last-Modified`, sent verbatim as `If-Modified-Since` when there is no ETag. Ignored for a paged Data Source. */
        public ?string $ifModifiedSince = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpoint_id' => $this->endpointId, 'endpoint_revision_id' => $this->endpointRevisionId, 'data_source_revision' => $this->dataSourceRevision, 'conditional' => $this->ifNoneMatch !== null || $this->ifModifiedSince !== null];
    }
}
