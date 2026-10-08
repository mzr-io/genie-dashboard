<?php

namespace App\Platform\Json;

use RuntimeException;

/**
 * The body is not JSON (RFC 8259). The message names the kind of fault and the byte offset only, never any of the body.
 */
class JsonParseFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $offset = null)
    {
        parent::__construct($offset === null ? "Invalid JSON: {$reason}." : "Invalid JSON: {$reason} at byte {$offset}.");
    }
}
