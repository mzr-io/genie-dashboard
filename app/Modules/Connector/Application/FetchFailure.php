<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\ConnectionTestCode;

/**
 * What the error ladder ({@see EndpointFetchLadder}) makes of a failed fetch: the user code and the reason, and the numbers the failure
 * itself knew (a field left null was not learned from the failure and keeps what the caller already had). Never a URL, a value or a body.
 */
final readonly class FetchFailure
{
    public function __construct(
        public ConnectionTestCode $code,
        public string $reason,
        public ?int $latencyMs = null,
        public ?int $status = null,
        public ?int $bytes = null,
        public ?int $limitBytes = null,
        public ?int $page = null,
        public ?int $pages = null,
    ) {}
}
