<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;

/** Performs one cURL transfer with exactly the options given. A seam for tests; the only implementation is NativeCurlClient. */
interface CurlClient
{
    /**
     * @param  array<int, mixed>  $options  CURLOPT_* => value
     * @param  int|null  $maxBytes  the most decompressed body bytes to read; null reads without a cap. The transfer is aborted the moment the count passes it
     *
     * @throws EgressTransportFailed
     * @throws ResponseLimitExceeded when the body passes `$maxBytes`
     */
    public function execute(array $options, ?int $maxBytes = null): CurlResult;
}
