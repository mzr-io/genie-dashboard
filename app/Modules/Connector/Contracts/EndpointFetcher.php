<?php

namespace App\Modules\Connector\Contracts;

/**
 * The scheduled fetch of an Endpoint (Story 2.14), for Ingestion: renders the request from the Endpoint revision and the target's values,
 * sends it through {@see FetchTransport} (so through the guard, pagination and the credential handling) and judges the answer with
 * the error ladder of the Endpoint test: a 2xx answer must be JSON that parses losslessly, anything else is a failure with a code and a
 * reason. It never throws for a failed fetch; it never stores anything. Only Endpoints that need no user context are fetched here, and
 * a POST only when the revision is read-only.
 */
interface EndpointFetcher
{
    public function fetch(EndpointFetchSpec $spec): EndpointFetchResult;
}
