<?php

namespace App\Modules\Connector\Contracts;

/** Why a host allowlist value was refused: the reason a client maps to its message. */
enum HostProblem: string
{
    case Empty = 'empty';
    case Whitespace = 'whitespace';
    case ForbiddenCharacter = 'forbidden_character';
    case NonAscii = 'non_ascii';
    case Malformed = 'malformed';
    case InvalidLabel = 'invalid_label';
    case TooLong = 'too_long';
    case NumericAddress = 'numeric_address';
    case BlockedAddress = 'blocked_address';
    case InvalidPort = 'invalid_port';
    case InvalidScheme = 'invalid_scheme';

    /** The request field the problem belongs to. */
    public function field(): string
    {
        return $this === self::InvalidScheme ? 'scheme' : 'host';
    }
}
