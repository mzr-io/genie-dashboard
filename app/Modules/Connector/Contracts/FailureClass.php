<?php

namespace App\Modules\Connector\Contracts;

/**
 * What a failed scheduled fetch means for the next step (Story 2.17), judged on the cause for a paged failure. Only `Transient` and
 * `Throttled` are retried; `Configuration` and `Data` never are (the Admin's setup or the source's answer has to change) and keep the last
 * good payload; `Ambiguous` is a POST that failed after the request may have been sent, so it is not repeated automatically.
 */
enum FailureClass: string
{
    /** A timeout, a connect, DNS or reset error, a 5xx (other than a 503 with a valid `Retry-After`) or a 408. */
    case Transient = 'transient';

    /** A 429, or a 503 with a valid `Retry-After`: the source asks for slower calls. */
    case Throttled = 'throttled';

    /** Every other 4xx, an authentication or TLS failure, a blocked address, a secret or setup problem. */
    case Configuration = 'configuration';

    /** Not JSON, over a size, depth or page limit, or a pagination failure. */
    case Data = 'data';

    /** A POST that failed after the request may have been sent. */
    case Ambiguous = 'ambiguous';

    public function retryable(): bool
    {
        return $this === self::Transient || $this === self::Throttled;
    }
}
