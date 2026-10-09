<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\EndpointPath;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\InvalidDataSourceUrl;
use App\Modules\Connector\Contracts\InvalidEndpointPath;
use App\Modules\Connector\Contracts\Pagination;
use App\Modules\Connector\Contracts\PaginationPath;
use App\Modules\Connector\Contracts\ReservedHeaders;
use App\Modules\Connector\Contracts\Retention;
use App\Modules\Connector\Contracts\SecretSlots;
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

    public const SECRET_VALUE_MAX = 2048;

    public const API_KEY_NAME_MAX = 128;

    public const PLACEMENTS = ['header', 'query'];

    public const CLIENT_ID_MAX = 255;

    public const SCOPE_MAX = 512;

    public const PARAM_MAX = 64;

    /** The longest health path (Story 2.18). */
    public const HEALTH_PATH_MAX = 255;

    public const PAGE_SIZE_MAX = 1000000;

    private const TOKEN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D';

    public function __construct(private readonly DataSourceSettings $settings) {}

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidDataSource
     */
    public function validate(#[\SensitiveParameter] array $raw): DataSourceInput
    {
        $errors = [];
        $reasons = [];
        $fail = function (string $field, string $reason, string $message) use (&$errors, &$reasons): void {
            $errors[$field][] = $message;
            $reasons[$field] ??= $reason;
        };

        $name = $this->name($raw['name'] ?? null, $fail);
        $url = $this->url($raw['base_url'] ?? null, $fail);
        $secretValues = [];
        $headers = $this->headers($raw['headers'] ?? [], $fail, $secretValues);
        $ceilings = $this->settings->ceilings();
        $timeout = $this->limit($raw['timeout_seconds'] ?? null, 'timeout_seconds', $ceilings->timeoutSeconds, 'timeout', $fail);
        $bytes = $this->limit($raw['max_response_bytes'] ?? null, 'max_response_bytes', $ceilings->maxResponseBytes, 'response size', $fail);
        $pages = $this->limit($raw['max_pages'] ?? null, 'max_pages', $ceilings->maxPages, 'page count', $fail);
        $live = $this->boolean($raw['live_capable'] ?? false, 'live_capable', $fail);
        $auth = $raw['auth_type'] ?? 'none';

        if (! is_string($auth) || ! in_array($auth, DataSourceInput::AUTH_TYPES, true)) {
            $fail('auth_type', 'auth-type-invalid', 'Choose an authentication type.');
            $auth = 'none';
        }

        [$tokenUrl, $clientId, $scope] = $this->oauth($auth, $raw, $fail);

        [$apiKeyName, $apiKeyPlacement] = $this->apiKey($auth, $raw, $headers, $fail);
        $this->secrets($auth, $raw['secrets'] ?? null, $secretValues, $fail);
        $pagination = $this->pagination($raw, $fail);
        $retention = $this->retention($raw, $fail);
        $healthPath = $this->healthPath($raw['health_path'] ?? null, $fail);

        if ($errors !== [] || $name === null || $url === null) {
            throw new InvalidDataSource($errors, $reasons);
        }

        return new DataSourceInput($name, $url, $headers, $timeout, $bytes, $pages, $live, $auth, $apiKeyName, $apiKeyPlacement, $secretValues, $tokenUrl, $clientId, $scope, $pagination, $retention, $healthPath);
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

    /**
     * The token URL alone (the same rules as a Base URL), for the blur check. @throws InvalidDataSource
     */
    public function tokenUrl(mixed $value, ?callable $fail = null): ?DataSourceUrl
    {
        try {
            return DataSourceUrl::parse($value);
        } catch (InvalidDataSourceUrl $e) {
            $message = str_replace('base URL', 'token URL', $e->problem->message());

            if ($fail === null) {
                throw new InvalidDataSource(['oauth_token_url' => [$message]], ['oauth_token_url' => $e->problem->value]);
            }

            $fail('oauth_token_url', $e->problem->value, $message);

            return null;
        }
    }

    /**
     * The OAuth2 client credentials fields: the token URL, the client ID (a plain value) and the optional scope; all null
     * for any other type, whatever was posted.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: ?DataSourceUrl, 1: ?string, 2: ?string}
     */
    private function oauth(string $auth, #[\SensitiveParameter] array $raw, callable $fail): array
    {
        if ($auth !== 'oauth2_client_credentials') {
            return [null, null, null];
        }

        $url = $this->tokenUrl($raw['oauth_token_url'] ?? null, $fail);
        $id = $raw['oauth_client_id'] ?? null;
        $scope = $raw['oauth_scope'] ?? null;

        if (! is_string($id) || $id === '') {
            $fail('oauth_client_id', 'oauth-client-id-required', 'Enter the client ID.');
            $id = null;
        } elseif (strlen($id) > self::CLIENT_ID_MAX || preg_match('/\A[\x21-\x7e]+\z/D', $id) !== 1) {
            $fail('oauth_client_id', 'oauth-client-id-invalid', 'The client ID uses visible ASCII characters only, without spaces, up to '.self::CLIENT_ID_MAX.' characters.');
            $id = null;
        }

        if ($scope === null || $scope === '') {
            $scope = null;
        } elseif (! is_string($scope) || strlen($scope) > self::SCOPE_MAX || preg_match('/\A[\x21\x23-\x5b\x5d-\x7e]+(?: [\x21\x23-\x5b\x5d-\x7e]+)*\z/D', $scope) !== 1) {
            $fail('oauth_scope', 'oauth-scope-invalid', 'The scope is names separated by single spaces, up to '.self::SCOPE_MAX.' characters.');
            $scope = null;
        }

        return [$url, $id, $scope];
    }

    /**
     * The pagination settings (Story 2.11). Only what the style needs is kept (the rest is dropped, whatever was posted): `page`,
     * `offset` and `cursor` need the parameter that carries the page, the offset or the cursor, `cursor` also the path of the
     * next cursor, a page size is optional (name and number together), and the records path is optional (empty: the response
     * root is the array).
     *
     * @param  array<string, mixed>  $raw
     */
    private function pagination(#[\SensitiveParameter] array $raw, callable $fail): Pagination
    {
        $style = $raw['pagination_style'] ?? 'none';

        if ($style === '') {
            $style = 'none';
        }

        if (! is_string($style) || ! in_array($style, Pagination::STYLES, true)) {
            $fail('pagination_style', 'pagination-style-invalid', 'Choose how this API pages its answers.');

            return new Pagination;
        }

        if ($style === 'none') {
            return new Pagination;
        }

        $param = null;

        if (Pagination::needsParam($style)) {
            $param = $this->paramName($raw['pagination_param'] ?? null, 'pagination_param', 'pagination-param-required', $style === 'cursor' ? 'Enter the query parameter that carries the cursor.' : ($style === 'page' ? 'Enter the query parameter that carries the page number.' : 'Enter the query parameter that carries the offset.'), $fail);
        }

        // A page size applies to page, offset and cursor; a Link header carries its own, so none is kept for it.
        $sizeParam = $style === 'link_header' ? null : $this->optional($raw['pagination_size_param'] ?? null);
        $size = $style === 'link_header' ? null : $this->optional($raw['pagination_size'] ?? null);
        $sizeValue = null;

        if ($sizeParam !== null || $size !== null) {
            $sizeParam = $this->paramName($sizeParam, 'pagination_size_param', 'pagination-size-param-required', 'Enter the query parameter that carries the page size, or clear the page size.', $fail);

            if ($sizeParam !== null && $sizeParam === $param) {
                $fail('pagination_size_param', 'pagination-size-param-duplicate', 'The page size needs a different query parameter from the page, offset or cursor.');
                $sizeParam = null;
            }

            if (is_string($size) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $size) === 1) {
                $size = (int) $size;
            }

            if (! is_int($size) || $size < 1 || $size > self::PAGE_SIZE_MAX) {
                $fail('pagination_size', 'pagination-size-invalid', 'Enter a whole number greater than zero for the page size, or clear the page size parameter.');
            } else {
                $sizeValue = $size;
            }
        }

        $recordsPath = $this->path($raw['pagination_records_path'] ?? null, 'pagination_records_path', false, $fail);
        $cursorPath = $style === 'cursor' ? $this->path($raw['pagination_cursor_path'] ?? null, 'pagination_cursor_path', true, $fail) : null;

        return new Pagination($style, $param, $sizeParam, $sizeValue, $recordsPath, $cursorPath);
    }

    /**
     * The retention setting (Story 2.16): `latest` (the default; no days may be sent) or `window` with a whole number of days from 1 to
     * the deployment's maximum. With no maximum set a window is refused: nothing is invented and the form shows why.
     *
     * @param  array<string, mixed>  $raw
     */
    private function retention(#[\SensitiveParameter] array $raw, callable $fail): Retention
    {
        $mode = $raw['retention_mode'] ?? 'latest';
        $days = $this->optional($raw['retention_days'] ?? null);

        if ($mode === '') {
            $mode = 'latest';
        }

        if (! is_string($mode) || ! in_array($mode, Retention::MODES, true)) {
            $fail('retention_mode', 'retention-mode-invalid', 'Choose how much raw history to keep.');

            return new Retention;
        }

        if ($mode === 'latest') {
            if ($days !== null) {
                $fail('retention_days', 'retention-days-not-allowed', 'A number of days applies only to a window of history.');
            }

            return new Retention;
        }

        $max = $this->settings->retentionMaxWindowDays();

        if ($max === null) {
            $fail('retention_days', 'retention-window-unavailable', 'Keeping a window of history is not available: no maximum is set for this deployment.');

            return new Retention;
        }

        if (is_string($days) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $days) === 1) {
            $days = (int) $days;
        }

        if (! is_int($days) || $days < 1) {
            $fail('retention_days', 'retention-days-invalid', 'Enter a whole number of days, 1 or more.');

            return new Retention;
        }

        if ($days > $max) {
            $fail('retention_days', 'retention-days-above-maximum', "The window cannot be more than {$max} days.");

            return new Retention;
        }

        return new Retention('window', $days);
    }

    /**
     * The optional health path (Story 2.18), validated like an Endpoint path ({@see EndpointPath}: it starts with `/`, is relative, has no
     * query, fragment, `.` or `..` segment, space or control character) with two more rules: at most 255 characters and no `{placeholder}`
     * (nothing fills one in for a probe). Blank means none.
     */
    private function healthPath(mixed $value, callable $fail): ?string
    {
        $value = $this->optional($value);

        if ($value === null) {
            return null;
        }

        if (is_string($value) && strlen($value) > self::HEALTH_PATH_MAX) {
            $fail('health_path', 'health-path-too-long', 'The health path can have at most '.self::HEALTH_PATH_MAX.' characters.');

            return null;
        }

        try {
            $path = EndpointPath::parse($value);
        } catch (InvalidEndpointPath $e) {
            // The Endpoint path's own reason, prefixed so the form maps it; one without a label there shows this message.
            $reason = 'health-'.$e->reason;
            $fail('health_path', $reason, $e->getMessage());

            return null;
        }

        if ($path->placeholders() !== [] || str_contains($path->template, '{') || str_contains($path->template, '}')) {
            $fail('health_path', 'health-path-placeholder', 'The health path cannot hold {placeholders}: enter a fixed path such as /health.');

            return null;
        }

        return $path->template;
    }

    private function optional(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
    }

    /** A query parameter name: a short token of unreserved characters. */
    private function paramName(mixed $value, string $field, string $reason, string $required, callable $fail): ?string
    {
        if (! is_string($value) || $value === '') {
            $fail($field, $reason, $required);

            return null;
        }

        if (strlen($value) > self::PARAM_MAX || preg_match('/\A[A-Za-z0-9_.~-]+\z/D', $value) !== 1) {
            $fail($field, 'pagination-param-invalid', 'A query parameter name uses letters, digits and the characters _ . ~ - only, up to '.self::PARAM_MAX.' characters.');

            return null;
        }

        return $value;
    }

    /** A dotted path into the response ({@see PaginationPath}); blank means "none" unless it is required. */
    private function path(mixed $value, string $field, bool $required, callable $fail): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                $fail($field, 'pagination-path-required', 'Enter where the next cursor sits in the response, such as meta.next.');
            }

            return null;
        }

        if (! is_string($value) || ! PaginationPath::valid($value)) {
            $fail($field, 'pagination-path-invalid', 'A path is keys and array positions separated by dots, such as data.items: letters, digits, _ and - only, at most '.PaginationPath::MAX_SEGMENTS.' parts.');

            return null;
        }

        return $value;
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

    /**
     * @param  array<string, string>  $secretValues  filled with the value of each secret header being set, by slot
     * @return list<array{name: string, value: string, secret?: true}>
     */
    private function headers(#[\SensitiveParameter] mixed $value, callable $fail, #[\SensitiveParameter] array &$secretValues): array
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
            $secret = is_array($row) && filter_var($row['secret'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $nameOk = $this->headerName($name, "headers.{$i}.name", $seen, $fail);

            if ($secret) {
                // A secret header keeps only its name and the flag; an absent value leaves the saved secret as it is.
                $valueOk = ! array_key_exists('value', $row) || $this->secretValue($text, "headers.{$i}.value", $fail);

                if ($nameOk && $valueOk) {
                    $headers[] = ['name' => (string) $name, 'value' => '', 'secret' => true];
                    $seen[strtolower((string) $name)] = true;

                    if (array_key_exists('value', $row)) {
                        $secretValues[SecretSlots::header((string) $name)] = (string) $text;
                    }
                }

                continue;
            }

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

    /**
     * The API key's header or query parameter name and where it travels; both null for any other type.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<array{name: string, value: string, secret?: true}>  $headers
     * @return array{0: ?string, 1: ?string}
     */
    private function apiKey(string $auth, #[\SensitiveParameter] array $raw, array $headers, callable $fail): array
    {
        if ($auth !== 'api_key') {
            return [null, null];
        }

        $name = $raw['api_key_name'] ?? null;
        $placement = $raw['api_key_placement'] ?? 'header';
        $ok = true;

        if (! is_string($placement) || ! in_array($placement, self::PLACEMENTS, true)) {
            $fail('api_key_placement', 'api-key-placement-invalid', 'Choose header or query string.');
            $placement = null;
        }

        if (! is_string($name) || $name === '') {
            $fail('api_key_name', 'api-key-name-required', 'Enter the name the API key is sent under.');
            $ok = false;
        } elseif (strlen($name) > self::API_KEY_NAME_MAX || preg_match(self::TOKEN, $name) !== 1) {
            $fail('api_key_name', 'api-key-name-invalid', 'The name uses letters, digits and the characters ! # $ % & \' * + - . ^ _ ` | ~ only.');
            $ok = false;
        } elseif ($placement === 'query' && preg_match('/\A[A-Za-z0-9_.~-]+\z/D', $name) !== 1) {
            $fail('api_key_name', 'api-key-name-invalid', 'A query parameter name uses letters, digits and the characters _ . ~ - only.');
            $ok = false;
        } elseif ($placement === 'header' && (ReservedHeaders::transport($name) || ReservedHeaders::credential($name))) {
            $fail('api_key_name', 'api-key-name-reserved', 'This header name is reserved. Choose another name.');
            $ok = false;
        } elseif ($placement === 'header' && array_filter($headers, fn (array $h): bool => strcasecmp($h['name'], $name) === 0) !== []) {
            $fail('api_key_name', 'api-key-name-duplicate', 'This name is already used by a default header.');
            $ok = false;
        }

        return [$ok ? (string) $name : null, $placement];
    }

    /**
     * Checks the values posted for the secret slots of this type; each is added to `$secretValues` (an absent slot stays as saved).
     *
     * @param  array<string, string>  $secretValues
     */
    private function secrets(string $auth, #[\SensitiveParameter] mixed $given, #[\SensitiveParameter] array &$secretValues, callable $fail): void
    {
        if ($given === null || $given === [] || $given === '') {
            return;
        }

        if (! is_array($given) || array_is_list($given)) {
            $fail('secrets', 'secrets-invalid', 'The credentials are not valid.');

            return;
        }

        $allowed = SecretSlots::forAuth($auth);

        foreach ($given as $slot => $value) {
            $slot = (string) $slot;

            if (! in_array($slot, $allowed, true)) {
                $fail("secrets.{$slot}", 'secret-slot-unused', 'This credential does not belong to the chosen authentication type.');

                continue;
            }

            if ($slot === SecretSlots::BASIC_USERNAME && is_string($value) && str_contains($value, ':')) {
                // A colon would split the Basic credentials in the wrong place.
                $fail("secrets.{$slot}", 'secret-value-invalid', 'A user name cannot contain a colon.');

                continue;
            }

            if ($this->secretValue($value, "secrets.{$slot}", $fail)) {
                $secretValues[$slot] = (string) $value;
            }
        }
    }

    /** A secret's value: required, visible ASCII (0x20 to 0x7E) on one line, never trimmed or repaired. */
    private function secretValue(#[\SensitiveParameter] mixed $value, string $field, callable $fail): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $fail($field, 'secret-required', 'Enter a value.');

            return false;
        }

        if (! is_string($value) || preg_match('/\A[\x20-\x7e]+\z/D', $value) !== 1) {
            $fail($field, 'secret-value-invalid', 'A credential uses visible ASCII characters only, on one line.');

            return false;
        }

        if (strlen($value) > self::SECRET_VALUE_MAX) {
            $fail($field, 'secret-value-too-long', 'A credential can have at most '.self::SECRET_VALUE_MAX.' characters.');

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
