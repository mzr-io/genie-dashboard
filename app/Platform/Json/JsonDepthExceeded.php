<?php

namespace App\Platform\Json;

/** The body nests deeper than the depth limit. Raised by a pre-scan, before any value is built. */
final class JsonDepthExceeded extends JsonParseFailed
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct('nesting is deeper than the limit');
    }
}
