<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\EgressTransportFailed;

/** Performs one cURL transfer with exactly the options given. A seam for tests; the only implementation is NativeCurlClient. */
interface CurlClient
{
    /**
     * @param  array<int, mixed>  $options  CURLOPT_* => value
     *
     * @throws EgressTransportFailed
     */
    public function execute(array $options): CurlResult;
}
