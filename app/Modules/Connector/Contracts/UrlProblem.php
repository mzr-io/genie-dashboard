<?php

namespace App\Modules\Connector\Contracts;

/** Why a Data Source Base URL was refused: the reason a client maps to its message. */
enum UrlProblem: string
{
    case Empty = 'empty';
    case TooLong = 'too-long';
    case Whitespace = 'whitespace';
    case Malformed = 'malformed';
    case Scheme = 'scheme';
    case Userinfo = 'userinfo';
    case Query = 'query';
    case Fragment = 'fragment';
    case InvalidHost = 'invalid-host';

    public function message(): string
    {
        return match ($this) {
            self::Empty => 'Enter the base URL.',
            self::TooLong => 'The base URL is too long.',
            self::Whitespace => 'The base URL cannot contain spaces.',
            self::Malformed => 'Enter a full address such as https://api.example.com/v1.',
            self::Scheme => 'The base URL must start with http:// or https://.',
            self::Userinfo => 'Leave the user name and password out of the base URL.',
            self::Query => 'Leave the query string out of the base URL.',
            self::Fragment => 'Leave the fragment (#) out of the base URL.',
            self::InvalidHost => 'The host in the base URL is not valid.',
        };
    }
}
