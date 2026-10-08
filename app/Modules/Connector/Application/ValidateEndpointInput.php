<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\EndpointPath;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\InvalidEndpointBody;
use App\Modules\Connector\Contracts\InvalidEndpointPath;
use App\Modules\Connector\Contracts\ReservedHeaders;

/**
 * Turns the raw request fields of an Endpoint into an {@see EndpointInput}, or refuses them all at once with a field error and
 * a reason each (HTTP 422; nothing is written). The rules are the server's alone and nothing is trimmed or repaired.
 *
 * Method: only `GET` and `POST`, exactly (anything else, lower case and empty included, is `method-not-allowed`). A POST
 * is accepted only as a read-only query with the risk confirmation (`read_only_query` and `confirm_read_only`, both true).
 * Path: {@see EndpointPath}. Parameters and headers are `{name, binding, value}` rows with the bindings `fixed` (a string
 * value is required), the four date and period bindings (the value is stored as null; they resolve at fetch time, which
 * nothing here does) and, since Story 2.13, the four user-context bindings: `user_id`, `user_email` and `user_group` store a null
 * value and `user_attribute` stores a defined attribute key id (an unknown one is `binding-attribute-unknown`). A user binding
 * never carries a value of a user, only the key: the stored revision cannot hold one. `scope_by_caller` is accepted only when a
 * user binding exists (`scope-requires-user-context`). A header name is an HTTP token that is neither reserved nor a
 * credential name, and a fixed header value is visible ASCII only (CR, LF and every other control or non-ASCII byte is
 * refused). A parameter is a path parameter when the path has its placeholder, a body parameter when the body template
 * references it, and a query parameter otherwise. Nothing here contacts any host.
 */
final class ValidateEndpointInput
{
    public const PARAMS_MAX = 25;

    public const HEADERS_MAX = 25;

    public const NAME_MAX = 64;

    public const HEADER_NAME_MAX = 128;

    public const VALUE_MAX = 2048;

    private const QUERY_NAME = '/\A[A-Za-z0-9_.~\-]{1,64}\z/D';

    /** @var list<string> the defined attribute key ids of the call being validated */
    private array $attributeKeys = [];

    private const TOKEN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D';

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $attributeKeys  the key ids the Workspace defines (Story 2.12); a `user_attribute` binding must name one
     *
     * @throws InvalidDataSource
     */
    public function validate(array $raw, array $attributeKeys = []): EndpointInput
    {
        $this->attributeKeys = $attributeKeys;

        $errors = [];
        $reasons = [];
        $fail = function (string $field, string $reason, string $message) use (&$errors, &$reasons): void {
            $errors[$field][] = $message;
            $reasons[$field] ??= $reason;
        };

        $method = $this->method($raw['method'] ?? null, $fail);
        $path = $this->path($raw['path'] ?? null, $fail);
        $placeholders = $path?->placeholders() ?? [];
        $params = $this->params($raw['params'] ?? [], $fail);
        $headers = $this->headers($raw['headers'] ?? [], $fail);
        $declared = array_column($params, 'name');

        if ($path !== null) {
            foreach ($placeholders as $name) {
                if (! in_array($name, $declared, true)) {
                    $fail('path', 'path-param-missing', "The path uses {$name}, but no parameter named {$name} is declared. Add it to the parameters.");
                }
            }
        }

        [$body, $refs] = $this->body($raw['body_template'] ?? null, $method, $declared, $fail);
        $kinds = [];

        foreach ($params as $i => $param) {
            $kind = in_array($param['name'], $placeholders, true) ? 'path' : (in_array($param['name'], $refs, true) ? 'body' : 'query');
            $kinds[$i] = $kind;

            if ($kind === 'path' && $param['binding'] === 'fixed' && ! EndpointPath::valueAllowed((string) $param['value'])) {
                $fail("params.{$i}.value", 'param-value-invalid', "The value of {$param['name']} cannot be empty, '.', '..' or contain '/'.");
            }
        }

        $readOnly = $this->readOnly($raw, $method, $fail);
        $scope = $this->scope($raw['scope_by_caller'] ?? null, [...$params, ...$headers], $fail);

        if ($errors !== [] || $method === null || $path === null) {
            throw new InvalidDataSource($errors, $reasons);
        }

        foreach ($params as $i => $_) {
            $params[$i]['kind'] = $kinds[$i];
        }

        $params = array_values($params);

        /** @var list<array{name: string, binding: string, value: string|null, kind: string}> $params */
        return new EndpointInput($method, $path, $params, $headers, $body, $readOnly, $scope);
    }

    private function method(mixed $value, callable $fail): ?string
    {
        if (is_string($value) && in_array($value, EndpointInput::METHODS, true)) {
            return $value;
        }

        $fail('method', 'method-not-allowed', 'Only GET and POST are allowed. Dashflow never sends a request that changes data.');

        return null;
    }

    private function path(mixed $value, callable $fail): ?EndpointPath
    {
        try {
            return EndpointPath::parse($value);
        } catch (InvalidEndpointPath $e) {
            $fail('path', $e->reason, $e->getMessage());

            return null;
        }
    }

    /**
     * A POST is a read-only query, confirmed; a GET stores the flag as false whatever was posted.
     *
     * @param  array<string, mixed>  $raw
     */
    private function readOnly(array $raw, ?string $method, callable $fail): bool
    {
        if ($method !== 'POST') {
            return false;
        }

        if (($raw['read_only_query'] ?? null) !== true) {
            $fail('read_only_query', 'post-readonly-required', 'A POST must be marked as a read-only query.');

            return false;
        }

        if (($raw['confirm_read_only'] ?? null) !== true) {
            $fail('confirm_read_only', 'post-confirmation-required', 'Confirm that this POST only reads data.');

            return false;
        }

        return true;
    }

    /**
     * The optional `scope_by_caller` flag: only a revision with a user-context binding may set it (the membership ID joins the fetch key, Story 2.14).
     *
     * @param  list<array{name: string, binding: string, value: string|null}>  $rows
     */
    private function scope(mixed $value, array $rows, callable $fail): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if ($value !== true) {
            $fail('scope_by_caller', 'scope-invalid', 'The scope flag must be true or false.');

            return false;
        }

        foreach ($rows as $row) {
            if (in_array($row['binding'], EndpointInput::USER_BINDINGS, true)) {
                return true;
            }
        }

        $fail('scope_by_caller', 'scope-requires-user-context', 'Scoping by the caller needs a parameter or header bound to user context.');

        return false;
    }

    /**
     * The parameters: a list of `{name, binding, value}`.
     *
     * @return array<int, array{name: string, binding: string, value: string|null}>
     */
    private function params(mixed $value, callable $fail): array
    {
        $field = 'params';

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $fail($field, 'params-invalid', 'The parameters are not valid.');

            return [];
        }

        if (count($value) > self::PARAMS_MAX) {
            $fail($field, 'too-many-params', 'Use at most '.self::PARAMS_MAX.' parameters.');

            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($value as $i => $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $binding = is_array($row) ? ($row['binding'] ?? null) : null;
            $text = is_array($row) ? ($row['value'] ?? null) : null;
            $nameOk = $this->paramName($name, "{$field}.{$i}.name", $seen, $fail);
            $bindingOk = $this->binding($binding, "{$field}.{$i}.binding", $fail);

            if ($nameOk && $bindingOk) {
                $seen[(string) $name] = true;
            }

            $valueOk = $bindingOk && $this->bindingValue((string) $binding, $text, "{$field}.{$i}.value", false, $fail);

            if ($nameOk && $bindingOk && $valueOk) {
                // Keyed by the row's own index, so an error on a later row names the right form row.
                $rows[$i] = ['name' => (string) $name, 'binding' => (string) $binding, 'value' => $this->storedValue((string) $binding, $text)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function paramName(mixed $name, string $field, array $seen, callable $fail): bool
    {
        if (! is_string($name) || preg_match(self::QUERY_NAME, $name) !== 1) {
            $fail($field, 'param-name-invalid', 'A parameter name uses letters, digits and the characters _ . ~ - only, up to '.self::NAME_MAX.' characters.');

            return false;
        }

        if (isset($seen[$name])) {
            $fail($field, 'param-name-duplicate', 'This parameter is already listed.');

            return false;
        }

        return true;
    }

    private function binding(mixed $binding, string $field, callable $fail): bool
    {
        if (is_string($binding) && in_array($binding, EndpointInput::BINDINGS, true)) {
            return true;
        }

        $fail($field, 'binding-invalid', 'Choose Fixed value, a date range bound, a period bound or user context.');

        return false;
    }

    /** The value a binding needs: a fixed one, a defined attribute key id, or nothing (date, period, user ID, email and group). */
    private function bindingValue(string $binding, mixed $text, string $field, bool $header, callable $fail): bool
    {
        if ($binding === 'fixed') {
            return $this->fixedValue($text, $field, $header, $fail);
        }

        if ($binding === 'user_attribute' && (! is_string($text) || ! in_array($text, $this->attributeKeys, true))) {
            // Shown on the Binding select, the only control a user-bound row has.
            $fail(substr($field, 0, -strlen('value')).'binding', 'binding-attribute-unknown', 'Choose one of the attributes defined for this workspace.');

            return false;
        }

        return true;
    }

    private function storedValue(string $binding, mixed $text): ?string
    {
        return $binding === 'fixed' || $binding === 'user_attribute' ? (string) $text : null;
    }

    /** A fixed value: required, a string; a header value is visible ASCII on one line. */
    private function fixedValue(mixed $value, string $field, bool $header, callable $fail): bool
    {
        if (! is_string($value) || $value === '') {
            $fail($field, 'param-value-required', 'Enter a value.');

            return false;
        }

        if ($header && preg_match('/\A[\x20-\x7e]*\z/D', $value) !== 1) {
            $fail($field, 'header-value-invalid', 'A header value uses visible ASCII characters only, on one line.');

            return false;
        }

        if (! $header && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1)) {
            $fail($field, 'param-value-invalid', 'The value cannot contain control characters.');

            return false;
        }

        if (strlen($value) > self::VALUE_MAX) {
            $fail($field, 'param-value-too-long', 'The value can have at most '.self::VALUE_MAX.' characters.');

            return false;
        }

        return true;
    }

    /**
     * @return list<array{name: string, binding: string, value: string|null}>
     */
    private function headers(mixed $value, callable $fail): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $fail('headers', 'headers-invalid', 'The headers are not valid.');

            return [];
        }

        if (count($value) > self::HEADERS_MAX) {
            $fail('headers', 'too-many-headers', 'Use at most '.self::HEADERS_MAX.' headers.');

            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($value as $i => $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $binding = is_array($row) ? ($row['binding'] ?? null) : null;
            $text = is_array($row) ? ($row['value'] ?? null) : null;
            $nameOk = $this->headerName($name, "headers.{$i}.name", $seen, $fail);
            $bindingOk = $this->binding($binding, "headers.{$i}.binding", $fail);
            $valueOk = $bindingOk && $this->bindingValue((string) $binding, $text, "headers.{$i}.value", true, $fail);

            if ($nameOk) {
                $seen[strtolower((string) $name)] = true;
            }

            if ($nameOk && $bindingOk && $valueOk) {
                $rows[] = ['name' => (string) $name, 'binding' => (string) $binding, 'value' => $this->storedValue((string) $binding, $text)];
            }
        }

        return $rows;
    }

    /** @param  array<string, true>  $seen */
    private function headerName(mixed $name, string $field, array $seen, callable $fail): bool
    {
        if (! is_string($name) || $name === '' || strlen($name) > self::HEADER_NAME_MAX || preg_match(self::TOKEN, $name) !== 1) {
            $fail($field, 'header-name-invalid', 'A header name uses letters, digits and the characters ! # $ % & \' * + - . ^ _ ` | ~ only.');

            return false;
        }

        if (ReservedHeaders::transport($name) || ReservedHeaders::credential($name)) {
            $fail($field, 'header-name-reserved', 'This header cannot be set on an endpoint. Credentials are added to the data source.');

            return false;
        }

        if (isset($seen[strtolower($name)])) {
            $fail($field, 'header-name-duplicate', 'This header is already listed.');

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $declared
     * @return array{0: mixed, 1: list<string>} the tree to store (null: none) and the parameter names it references
     */
    private function body(mixed $value, ?string $method, array $declared, callable $fail): array
    {
        if ($value === null || $value === '') {
            return [null, []];
        }

        if (! is_string($value)) {
            $fail('body_template', 'body-template-invalid', "The body template isn't valid JSON.");

            return [null, []];
        }

        if ($method !== 'POST') {
            $fail('body_template', 'body-template-not-allowed', 'Only a POST endpoint has a body template.');

            return [null, []];
        }

        try {
            return EndpointBodyTemplate::parse($value, $declared);
        } catch (InvalidEndpointBody $e) {
            $fail('body_template', $e->reason, $e->getMessage());

            return [null, []];
        }
    }
}
