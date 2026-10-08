<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;

/** The curl handler (ext-curl), the only HTTP client the egress path uses. */
final class NativeCurlClient implements CurlClient
{
    /**
     * Adds one header line. A status line starts a new block (a 100 Continue or redirect hop before the final response),
     * so only the final response's headers remain.
     *
     * @param  array<string, list<string>>  $headers
     */
    public static function collect(array &$headers, string $line): void
    {
        if (str_starts_with($line, 'HTTP/')) {
            $headers = [];

            return;
        }

        $pair = explode(':', $line, 2);

        if (count($pair) === 2) {
            $headers[strtolower(trim($pair[0]))][] = trim($pair[1]);
        }
    }

    public function execute(array $options, ?int $maxBytes = null): CurlResult
    {
        $handle = curl_init();
        $headers = [];
        $body = '';
        $read = 0;
        $exceeded = false;
        $status = 0;

        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_HEADERFUNCTION] = function ($curl, string $line) use (&$headers, &$status): int {
            self::collect($headers, $line);

            // The status of the response being read (a 100 Continue or a hop before it is replaced by the next status line).
            if (preg_match('~\AHTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            }

            return strlen($line);
        };
        // The callback sees the body after curl has decompressed it (CURLOPT_ENCODING), so a gzip bomb is counted as it
        // inflates. On a 2xx answer (the data) the count passing the limit returns 0, which aborts the transfer before the
        // chunk is kept: memory never holds more than the limit, and a truncated body is never handed on (the abort raises).
        // Any other answer (an error page, a redirect) is not data: it is read up to the limit, the rest is dropped without
        // raising, and the response comes back with its real status and headers.
        $options[CURLOPT_WRITEFUNCTION] = function ($curl, string $chunk) use (&$body, &$read, &$exceeded, &$status, $maxBytes): int {
            $before = $read;
            $read += strlen($chunk);

            if ($maxBytes !== null && $read > $maxBytes) {
                if ($status >= 200 && $status < 300) {
                    $exceeded = true;

                    return 0;
                }

                $body .= substr($chunk, 0, max(0, $maxBytes - $before));

                return strlen($chunk);
            }

            $body .= $chunk;

            return strlen($chunk);
        };

        if (! curl_setopt_array($handle, $options)) {
            throw new EgressTransportFailed('The request could not be prepared.');
        }

        if (curl_exec($handle) === false) {
            if ($exceeded) {
                throw new ResponseLimitExceeded($read, (int) $maxBytes, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: null);
            }

            // The error number only: curl's text can name the address it tried.
            throw new EgressTransportFailed('The request failed (curl error '.curl_errno($handle).').', curl_errno($handle));
        }

        return new CurlResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
