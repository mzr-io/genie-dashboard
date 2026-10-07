<?php

namespace App\Support\Observability;

use Throwable;

/**
 * The one scrubbing layer for logs, spans and metric labels. Not configurable.
 */
final class Scrubber
{
    public const REDACTED = '[redacted]';

    public const HEADER_ALLOWLIST = ['content-type', 'accept', 'user-agent', 'x-request-id', 'content-length'];

    private const SPAN_ATTRIBUTES = [
        'http.request.method', 'http.response.status_code', 'http.route', 'http.request.body.size',
        'http.response.body.size', 'http.request.resend_count', 'url.full', 'url.path', 'url.scheme',
        'url.template', 'server.address', 'server.port', 'user_agent.original', 'network.protocol.name',
        'network.protocol.version', 'error.type', 'code.function', 'code.function.name', 'code.namespace',
        'code.filepath', 'code.lineno', 'code.column', 'exception.type', 'exception.message',
        'db.system', 'db.system.name', 'db.namespace', 'db.name', 'db.operation', 'db.operation.name',
        'db.collection.name', 'messaging.system', 'messaging.operation', 'messaging.operation.type',
        'messaging.operation.name', 'messaging.destination.name', 'messaging.message.id',
        'http.method', 'http.status_code', 'http.url', 'http.target', 'http.scheme', 'http.host',
        'http.user_agent', 'http.flavor', 'http.status_text', 'otel.status_code', 'otel.status_description',
    ];

    private const URL_ATTRIBUTES = ['url.full', 'url.path', 'url.template', 'http.url', 'http.target', 'http.route'];

    private const SPAN_PREFIXES = ['dashflow.'];

    private const HEADER_PREFIXES = ['http.request.header.', 'http.response.header.'];

    private const RESOURCE_PREFIXES = ['service.', 'telemetry.', 'deployment.', 'host.name', 'process.pid'];

    /** The one list of credential-like field names (substrings, case-insensitive), shared with the secret-value guard. */
    public const SENSITIVE_KEY = '/authorization|cookie|passw|secret|token|api[_-]?key|credential|signature|bearer|cipher|sealed|basic[_-]?user|private[_-]?key/i';

    public static function url(string $url): string
    {
        $url = (string) preg_replace('/[?#].*\z/s', '', $url);

        return (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', $url);
    }

    /** Strip query strings, fragments and userinfo from every URL found in free text. */
    public static function text(string $text): string
    {
        $text = (string) preg_replace_callback(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\'<>]+~i',
            fn (array $m): string => self::url($m[0]),
            $text,
        );

        return (string) preg_replace_callback(
            '~(?<![\w/:.])/[^\s"\'<>?#]*[?#][^\s"\'<>]*~',
            fn (array $m): string => self::url($m[0]),
            $text,
        );
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function headers(array $headers): array
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (in_array($name, self::HEADER_ALLOWLIST, true)) {
                $kept[$name] = self::value($value);
            }
        }

        return $kept;
    }

    /** Recursively scrub a log context value. */
    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return self::REDACTED;
        }

        if (is_string($value)) {
            return self::text($value);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => self::text($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if ($value instanceof \Stringable) {
            return self::text((string) $value);
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $name = strtolower((string) $key);
                if (in_array($name, ['headers', 'header'], true) && is_array($item)) {
                    $out[$key] = self::headers($item);
                } elseif (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                    $out[$key] = self::REDACTED;
                } else {
                    $out[$key] = self::value($item, $depth + 1);
                }
            }

            return $out;
        }

        return is_scalar($value) || $value === null ? $value : self::REDACTED;
    }

    /**
     * Span and event attributes: only allowlisted keys survive, values are scrubbed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function spanAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if (! self::spanAttributeAllowed($key) || preg_match(self::SENSITIVE_KEY, $key) === 1) {
                continue;
            }
            if (is_string($value)) {
                $value = in_array($key, self::URL_ATTRIBUTES, true) ? self::url($value) : self::text($value);
            } elseif (is_array($value)) {
                $value = array_map(fn ($v) => is_string($v) ? self::text($v) : $v, $value);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function resourceAttributes(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn ($_, $key) => array_filter(self::RESOURCE_PREFIXES, fn ($p) => str_starts_with((string) $key, $p)) !== [],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Metric labels: scalars only, sensitive keys dropped, strings scrubbed.
     *
     * @param  array<string, mixed>  $labels
     * @return array<string, bool|int|float|string>
     */
    public static function labels(array $labels): array
    {
        $out = [];
        foreach ($labels as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, (string) $key) === 1 || ! is_scalar($value)) {
                continue;
            }
            $out[(string) $key] = is_string($value) ? self::text($value) : $value;
        }

        return $out;
    }

    private static function spanAttributeAllowed(string $key): bool
    {
        if (in_array($key, self::SPAN_ATTRIBUTES, true)) {
            return true;
        }
        foreach (self::SPAN_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        foreach (self::HEADER_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $name = str_replace('_', '-', strtolower(substr($key, strlen($prefix))));

                return in_array($name, self::HEADER_ALLOWLIST, true);
            }
        }

        return false;
    }
}
