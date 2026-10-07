<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\EgressTransportFailed;

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

    public function execute(array $options): CurlResult
    {
        $handle = curl_init();
        $headers = [];
        $body = '';

        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_HEADERFUNCTION] = function ($curl, string $line) use (&$headers): int {
            self::collect($headers, $line);

            return strlen($line);
        };
        $options[CURLOPT_WRITEFUNCTION] = function ($curl, string $chunk) use (&$body): int {
            $body .= $chunk;

            return strlen($chunk);
        };

        if (! curl_setopt_array($handle, $options)) {
            throw new EgressTransportFailed('The request could not be prepared.');
        }

        if (curl_exec($handle) === false) {
            // The error number only: curl's text can name the address it tried.
            throw new EgressTransportFailed('The request failed (curl error '.curl_errno($handle).').');
        }

        return new CurlResult((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }
}
