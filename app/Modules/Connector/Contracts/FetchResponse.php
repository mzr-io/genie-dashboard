<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;

/**
 * What {@see FetchTransport} brings back from the final hop. The body is for the caller that parses it, through
 * {@see self::json()} (Story 2.6), the one check every fetch applies to a 2xx answer. It never appears in a dump.
 */
final readonly class FetchResponse
{
    /** @param  array<string, list<string>>  $headers  keyed by lower-case name */
    public function __construct(
        public int $status,
        public array $headers,
        #[\SensitiveParameter] public string $body,
        public int $bytes,
        public int $latencyMs,
        /** Pages fetched and read (Story 2.11): 1 for an unpaged call. A paged body is the merged document of all of them. */
        public int $pages = 1,
        /** For a paged call, the page this answer is the outcome of: the one that failed, else the last one fetched. Null when unpaged. */
        public ?int $page = null,
    ) {}

    /** True for HTTP 2xx. */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The body decoded losslessly ({@see LosslessJson}: every number is a `DecimalLiteral` with its exact lexeme), after
     * the JSON check: the media type must be `application/json` or `application/*+json` (any case; a `charset` parameter
     * only `utf-8`), and the body must be non-empty and parse within the depth limit. A missing Content-Type counts as not
     * JSON. Call it for any 2xx answer, so a login page or an error page that came back as HTTP 200 is never taken for data.
     *
     * @throws NotJsonResponse never retried, nothing stored
     * @throws InvalidLimitSetting when the depth limit is set but malformed (the fetch fails closed)
     */
    public function json(): mixed
    {
        return $this->check(true);
    }

    /**
     * The same check as {@see self::json()} for a caller that only needs to know the body is valid (Test connection): the
     * value is never built, so a large body does not cost many times its size in memory.
     *
     * @throws NotJsonResponse
     */
    public function assertJson(): void
    {
        $this->check(false);
    }

    private function check(bool $build): mixed
    {
        // Exactly one Content-Type value: a second, conflicting one is not judged by the first.
        if (count($this->headers['content-type'] ?? []) !== 1 || ! self::isJsonMediaType($this->headers['content-type'][0])) {
            throw new NotJsonResponse('content_type');
        }

        if ($this->body === '') {
            throw new NotJsonResponse('empty');
        }

        try {
            $depth = LosslessJson::configuredDepthLimit();

            if (! $build) {
                LosslessJson::validate($this->body, $depth);

                return null;
            }

            return LosslessJson::decode($this->body, $depth);
        } catch (JsonDepthExceeded $e) {
            throw new NotJsonResponse('too_deep', $e);
        } catch (JsonParseFailed $e) {
            throw new NotJsonResponse('parse', $e);
        }
    }

    /** Whether a Content-Type header value names JSON this platform accepts. */
    public static function isJsonMediaType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $parts = explode(';', $contentType);
        $type = strtolower(trim(array_shift($parts)));

        if (preg_match('~\Aapplication/(?:json|[a-z0-9!#$&^_.+-]+\+json)\z~D', $type) !== 1) {
            return false;
        }

        foreach ($parts as $parameter) {
            $pair = explode('=', $parameter, 2);

            if (strtolower(trim($pair[0])) === 'charset' && strtolower(trim($pair[1] ?? '', " \t\"")) !== 'utf-8') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'bytes' => $this->bytes, 'latencyMs' => $this->latencyMs];
    }
}
