<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** An outbound request was refused: by the guard, or by the transport on a redirect. Code `connector.ssrf_blocked`. */
final class SsrfBlocked extends RuntimeException
{
    public function __construct(public readonly EgressReason $reason, public readonly ?EgressVerdict $verdict = null)
    {
        parent::__construct(ErrorCode::SsrfBlocked->value.': '.$reason->value);
    }
}
