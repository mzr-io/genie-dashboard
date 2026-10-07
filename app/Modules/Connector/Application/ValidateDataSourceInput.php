<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\InvalidDataSourceUrl;
use App\Modules\Connector\Contracts\ReservedHeaders;
use App\Modules\Connector\Infrastructure\DataSourceSettings;

/**
 * Turns the raw request fields of a Data Source into a {@see DataSourceInput}, or refuses them all at once with a field
 * error and a reason each (HTTP 422; nothing is written). The rules are the server's alone and nothing but the name is
 * trimmed: a header value with CR, LF or any character outside visible ASCII (0x20 to 0x7E) is refused, never repaired.
 * Length limits are validation constants here, not tunables; the three limits are checked against the platform ceilings
 * that are set (an unset ceiling is not checked). Nothing here contacts any host.
 */
final class ValidateDataSourceInput
{
    public const NAME_MAX = 64;

    public const HEADER_NAME_MAX = 128;

    public const HEADER_VALUE_MAX = 2048;

    public const HEADERS_MAX = 25;

    public function __construct(private readonly DataSourceSettings $settings) {}

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidDataSource
     */
    public function validate(array $raw): DataSourceInput
    {
        $errors = [];
        $reasons = [];
        $fail = function (string $field, string $reason, string $message) use (&$errors, &$reasons): void {
            $errors[$field][] = $message;
            $reasons[$field] ??= $reason;
        };

        $name = $this->name($raw['name'] ?? null, $fail);
        $url = $this->url($raw['base_url'] ?? null, $fail);
        $headers = $this->headers($raw['headers'] ?? [], $fail);
        $ceilings = $this->settings->ceilings();
        $timeout = $this->limit($raw['timeout_seconds'] ?? null, 'timeout_seconds', $ceilings->timeoutSeconds, 'timeout', $fail);
        $bytes = $this->limit($raw['max_response_bytes'] ?? null, 'max_response_bytes', $ceilings->maxResponseBytes, 'response size', $fail);
        $pages = $this->limit($raw['max_pages'] ?? null, 'max_pages', $ceilings->maxPages, 'page count', $fail);
        $live = $this->boolean($raw['live_capable'] ?? false, 'live_capable', $fail);
        $auth = $raw['auth_type'] ?? 'none';

        // Only `none` is accepted until Story 2.4 adds credentials and their write-only secrets.
        if ($auth !== 'none') {
            $fail('auth_type', 'auth-type-unavailable', 'Authentication other than none is not available yet.');
        }

        if ($errors !== [] || $name === null || $url === null) {
            throw new InvalidDataSource($errors, $reasons);
        }

        return new DataSourceInput($name, $url, $headers, $timeout, $bytes, $pages, $live);
    }

    /** The Base URL alone, for the blur check. @throws InvalidDataSource */
    public function url(mixed $value, ?callable $fail = null): ?DataSourceUrl
    {
        try {
            return DataSourceUrl::parse($value);
        } catch (InvalidDataSourceUrl $e) {
            $message = $e->problem->message();

            if ($fail === null) {
                throw new InvalidDataSource(['base_url' => [$message]], ['base_url' => $e->problem->value]);
            }

            $fail('base_url', $e->problem->value, $message);

            return null;
        }
    }

    private function name(mixed $value, callable $fail): ?string
    {
        if (! is_string($value)) {
            $fail('name', 'name-required', 'Enter a name.');

            return null;
        }

        $name = trim($value);

        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|(?!\x{20})\p{Zs}/u', $name) === 1) {
            $fail('name', 'name-invalid-characters', 'The name contains characters that are not allowed.');

            return null;
        }

        if ($name === '') {
            $fail('name', 'name-required', 'Enter a name.');

            return null;
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            $fail('name', 'name-too-long', 'The name can have at most '.self::NAME_MAX.' characters.');

            return null;
        }

        return $name;
    }

    /** @return list<array{name: string, value: string}> */
    private function headers(mixed $value, callable $fail): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $fail('headers', 'headers-invalid', 'The default headers are not valid.');

            return [];
        }

        if (count($value) > self::HEADERS_MAX) {
            $fail('headers', 'too-many-headers', 'Use at most '.self::HEADERS_MAX.' default headers.');

            return [];
        }

        $headers = [];
        $seen = [];

        foreach ($value as $i => $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $text = is_array($row) ? ($row['value'] ?? null) : null;
            $nameOk = $this->headerName($name, "headers.{$i}.name", $seen, $fail);
            $valueOk = $this->headerValue($text, "headers.{$i}.value", $fail);

            if ($nameOk && $valueOk) {
                $headers[] = ['name' => (string) $name, 'value' => (string) $text];
                $seen[strtolower((string) $name)] = true;
            }
        }

        return $headers;
    }

    /** @param  array<string, true>  $seen */
    private function headerName(mixed $name, string $field, array $seen, callable $fail): bool
    {
        if (! is_string($name) || $name === '' || strlen($name) > self::HEADER_NAME_MAX || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1) {
            $fail($field, 'header-name-invalid', 'A header name uses letters, digits and the characters ! # $ % & \' * + - . ^ _ ` | ~ only.');

            return false;
        }

        if (ReservedHeaders::transport($name) || ReservedHeaders::credential($name)) {
            $fail($field, 'header-name-reserved', 'This header cannot be set as a default header. Credentials are added separately.');

            return false;
        }

        if (isset($seen[strtolower($name)])) {
            $fail($field, 'header-name-duplicate', 'This header is already listed.');

            return false;
        }

        return true;
    }

    private function headerValue(mixed $value, string $field, callable $fail): bool
    {
        // Visible ASCII (0x20 to 0x7E) only: CR, LF, NUL, other control and non-ASCII characters never reach storage.
        if (! is_string($value) || preg_match('/\A[\x20-\x7e]*\z/D', $value) !== 1) {
            $fail($field, 'header-value-invalid', 'A header value uses visible ASCII characters only, on one line.');

            return false;
        }

        if (strlen($value) > self::HEADER_VALUE_MAX) {
            $fail($field, 'header-value-too-long', 'A header value can have at most '.self::HEADER_VALUE_MAX.' characters.');

            return false;
        }

        return true;
    }

    private function limit(mixed $value, string $field, ?int $ceiling, string $noun, callable $fail): ?int
    {
        // Blank means "use the platform setting".
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            $fail($field, 'not-positive-integer', 'Enter a whole number greater than zero, or leave it blank to use the platform setting.');

            return null;
        }

        if ($ceiling !== null && $value > $ceiling) {
            $fail($field, 'above-ceiling', "The {$noun} cannot be more than the platform limit of {$ceiling}.");

            return null;
        }

        return $value;
    }

    private function boolean(mixed $value, string $field, callable $fail): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $fail($field, 'invalid-boolean', 'Choose on or off.');

        return false;
    }
}
