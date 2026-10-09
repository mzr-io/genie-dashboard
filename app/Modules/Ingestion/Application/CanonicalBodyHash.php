<?php

namespace App\Modules\Ingestion\Application;

use App\Platform\Json\LosslessJson;
use Throwable;

/**
 * The hash that tells whether a response body changed (Story 2.15): the sha256 (lower-case hex) of its canonical form. The body is decoded
 * by {@see LosslessJson} (never `json_decode`), then written in the layout of {@see Jcs}: object members sorted, no whitespace, strings
 * escaped one way, lists kept in order, and every number as the lexeme received (`1.0` and `1` differ). So a body that differs only in
 * whitespace or key order has the same hash, and one that differs in a digit does not.
 *
 * It is not the content address of RawStore (that is the sha256 of the exact bytes). A body that cannot be canonicalised (it does not
 * parse, is too deep or is not UTF-8) has no hash: the caller treats it as changed.
 */
final class CanonicalBodyHash
{
    /** The writer is recursive, so a body nested deeper than this (with no depth limit set) is not hashed. */
    private const FALLBACK_DEPTH = 512;

    /** The hash of the body, or null when it cannot be canonicalised. */
    public static function of(#[\SensitiveParameter] string $body): ?string
    {
        try {
            return hash('sha256', Jcs::encode(LosslessJson::decode($body, LosslessJson::configuredDepthLimit() ?? self::FALLBACK_DEPTH)));
        } catch (Throwable) {
            return null;
        }
    }
}
