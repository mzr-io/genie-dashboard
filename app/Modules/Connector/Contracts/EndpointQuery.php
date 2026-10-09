<?php

namespace App\Modules\Connector\Contracts;

/**
 * The one query-string builder of an Endpoint request (Story 2.10): every name and every value is percent-encoded
 * (RFC 3986, `rawurlencode`), so a value holding `&`, `=`, `#`, a space or a non-ASCII character can only ever be the
 * value it was given and never starts another parameter or ends the query. Nothing else puts a parameter in a URL.
 */
final class EndpointQuery
{
    /** @param  list<array{0: string, 1: string}>  $pairs */
    public static function build(array $pairs): string
    {
        return implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
    }

    /**
     * The URL with the pairs as its query string.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public static function append(string $url, array $pairs): string
    {
        if ($pairs === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').self::build($pairs);
    }
}
