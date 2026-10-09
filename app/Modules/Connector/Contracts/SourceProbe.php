<?php

namespace App\Modules\Connector\Contracts;

/**
 * The health probe of a Data Source (Story 2.18), for Ingestion: one GET to the Base URL, or the Base URL plus the Data Source's optional
 * `health_path`, with the default headers and credentials, through {@see FetchTransport} (so through the egress guard). The answer is ok
 * for a 2xx or a 304; no JSON is required and the body is dropped. It never retries, takes no governor token and never touches the breaker.
 * It writes one `sync_runs` row of kind `health_probe` (the Base URL only, never the health path, a query, a header or a secret) in the
 * caller's Workspace transaction and counts `dashflow.connector.health_probe`. It never throws for a failed call: a refusal, a timeout
 * or a bad status is a result with `ok` false and a code.
 */
interface SourceProbe
{
    /** The `sync_runs.kind` of a probe. */
    public const KIND = 'health_probe';

    /** @throws DataSourceNotFound when the Data Source is gone or not visible in the Workspace */
    public function probe(string $workspaceId, string $dataSourceId): ProbeResult;
}
