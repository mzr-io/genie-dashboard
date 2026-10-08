<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointPath;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\InvalidPathValue;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretStatus;
use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;

/**
 * Turns an Endpoint revision and an Admin's test values into the request to send (Story 2.10). The server alone renders it,
 * from the Endpoint's current revision; the client sends values, never a path, a query or a body.
 *
 * {@see self::values()} is the check made before anything is queued: every declared parameter needs a value (a fixed
 * parameter's stored value is the default; a date range or period parameter needs an ISO `YYYY-MM-DD` date), and each value
 * obeys the rules of a save: a path value is one segment ({@see EndpointPath::valueAllowed()}), a header value is visible ASCII
 * on one line, any other value has no control character. A refusal is a 422 whose field is `values.{name}` (a header bound
 * to a date is `header:{name}`) and whose message names the parameter, never the value.
 *
 * {@see self::request()} renders: {@see EndpointPath::render()} builds the path against the Data Source base URL only, the
 * other parameters go through the one query builder, the Endpoint's headers are applied after the Data Source defaults and a
 * body template has each whole-value `{"$param": name}` replaced by the value as a JSON string (a value can fill a position,
 * never change the shape of the JSON). The result holds the test values: it is kept out of every log and record.
 */
final class RenderEndpointRequest
{
    private const VALUE_MAX = 2048;

    private const DATE = '/\A(\d{4})-(\d{2})-(\d{2})\z/D';

    /**
     * @param  array<string, mixed>  $given  the Admin's values by parameter name
     * @return array<string, string> every declared parameter (and every header that needs one) with its value, `header:{name}` for a header
     *
     * @throws InvalidDataSource
     */
    public function values(Endpoint $endpoint, array $given): array
    {
        $errors = [];
        $reasons = [];
        $values = [];
        $fail = function (string $field, string $reason, string $message) use (&$errors, &$reasons): void {
            $errors["values.{$field}"][] = $message;
            $reasons["values.{$field}"] ??= $reason;
        };

        foreach ($given as $name => $value) {
            if (! is_string($value)) {
                $errors['values'][] = 'The test values are not valid.';
                $reasons['values'] = 'values-invalid';

                break;
            }
        }

        if ($errors === []) {
            foreach ($endpoint->params as $param) {
                $name = $param['name'];
                $value = $this->pick($given, $name, $param['binding'], $param['value']);
                $label = $name;

                if ($value === null || $value === '') {
                    $fail($name, 'param-value-required', "Enter a value for {$label}.");

                    continue;
                }

                if ($param['binding'] !== 'fixed') {
                    $this->date($value, $name, $label, $fail);
                } elseif (strlen($value) > self::VALUE_MAX || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    $fail($name, 'param-value-invalid', "The value of {$label} cannot contain control characters and can have at most ".self::VALUE_MAX.' characters.');

                    continue;
                }

                if ($param['kind'] === 'path' && ! EndpointPath::valueAllowed($value)) {
                    $fail($name, 'param-value-invalid', "The value of {$label} cannot be '.', '..' or contain '/'.");

                    continue;
                }

                $values[$name] = $value;
            }

            foreach ($endpoint->headers as $header) {
                $key = 'header:'.$header['name'];
                $value = $this->pick($given, $key, $header['binding'], $header['value']);

                if ($value === null || $value === '') {
                    $fail($key, 'param-value-required', "Enter a value for the {$header['name']} header.");

                    continue;
                }

                if ($header['binding'] !== 'fixed') {
                    $this->date($value, $key, "the {$header['name']} header", $fail);
                } elseif (strlen($value) > self::VALUE_MAX || preg_match('/\A[\x20-\x7e]*\z/D', $value) !== 1) {
                    $fail($key, 'header-value-invalid', "The value of the {$header['name']} header uses visible ASCII characters only, on one line.");

                    continue;
                }

                $values[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw new InvalidDataSource($errors, $reasons);
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values  as {@see self::values()} returned them
     * @param  array<string, SecretStatus>  $secrets  the Data Source's slot statuses
     *
     * @throws InvalidDataSource when a value does not fit (the Endpoint moved since it was checked)
     */
    public function request(string $workspaceId, DataSource $source, Endpoint $endpoint, array $values, array $secrets, ?string $operationId = null): FetchRequest
    {
        $pathValues = [];
        $inBody = [];
        $isPost = $endpoint->method === 'POST';
        $body = null;

        foreach ($endpoint->params as $param) {
            if (! isset($values[$param['name']])) {
                throw new InvalidDataSource(["values.{$param['name']}" => ["Enter a value for {$param['name']}."]], ["values.{$param['name']}" => 'param-value-required']);
            }

            if ($param['kind'] === 'path') {
                $pathValues[$param['name']] = $values[$param['name']];
            }
        }

        if ($isPost && $endpoint->bodyTemplate !== null) {
            [$body, $inBody] = $this->body($endpoint->bodyTemplate, $values);
        }

        // A parameter the body template uses fills its position there and is not sent in the query string as well.
        $query = [];

        foreach ($endpoint->params as $param) {
            if ($param['kind'] !== 'path' && $param['kind'] !== 'body' && ! in_array($param['name'], $inBody, true)) {
                $query[] = [$param['name'], $values[$param['name']]];
            }
        }

        try {
            $path = EndpointPath::render($endpoint->pathAst, $pathValues);
        } catch (InvalidPathValue $e) {
            throw new InvalidDataSource(["values.{$e->parameter}" => ["The value of {$e->parameter} cannot be empty, '.', '..' or contain '/'."]], ["values.{$e->parameter}" => 'param-value-invalid']);
        }

        $headers = [];

        foreach ($endpoint->headers as $header) {
            $headers[] = ['name' => $header['name'], 'value' => $values['header:'.$header['name']] ?? ''];
        }

        if ($body !== null) {
            $headers[] = ['name' => 'Content-Type', 'value' => 'application/json'];
        }

        $base = rtrim($source->baseUrl, '/');
        $refs = [];

        foreach ($secrets as $status) {
            if ($status->configured && $status->id !== null) {
                $refs[] = new SecretRef($status->id, $status->slot, secretVersion: $status->secretVersion ?? 1);
            }
        }

        $client = $secrets[SecretSlots::OAUTH_CLIENT_SECRET] ?? null;

        return new FetchRequest(
            $workspaceId, $source->id, $endpoint->id, $base.$endpoint->pathTemplate, array_column($endpoint->params, 'name'),
            CredentialScheme::forSource($source), $refs,
            array_map(fn (array $header): array => ($header['secret'] ?? false) === true ? ['name' => $header['name'], 'value' => '', 'secret' => true] : $header, $source->headers),
            $source->apiKeyName, $source->apiKeyPlacement, $source->timeoutSeconds, $endpoint->method, $source->maxResponseBytes,
            $source->oauthTokenUrl, $source->oauthClientId, $source->oauthScope, $client?->secretVersion,
            url: $base.$path, queryPairs: $query, endpointHeaders: $headers, body: $body,
            idempotencyKey: $isPost ? $operationId : null, readOnlyQuery: $endpoint->readOnlyQuery,
        );
    }

    /**
     * The value the Admin gave, else (for a fixed binding) the stored one.
     *
     * @param  array<string, mixed>  $given
     */
    private function pick(array $given, string $key, string $binding, ?string $stored): ?string
    {
        if (array_key_exists($key, $given)) {
            return is_string($given[$key]) ? $given[$key] : null;
        }

        return $binding === 'fixed' ? $stored : null;
    }

    private function date(string $value, string $field, string $label, callable $fail): void
    {
        if (preg_match(self::DATE, $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $fail($field, 'param-date-invalid', "Enter {$label} as a date written YYYY-MM-DD.");
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: string, 1: list<string>} the rendered JSON text and the parameter names the template used
     */
    private function body(string $template, array $values): array
    {
        $used = [];
        $filled = $this->fill(LosslessJson::decode($template), $values, $used);

        return [LosslessJson::canonicalOf($filled), array_values(array_unique($used))];
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $used
     */
    private function fill(mixed $node, array $values, array &$used): mixed
    {
        if ($node instanceof JsonObject) {
            if ($node->has('$param') && count($node) === 1) {
                $name = $node->get('$param');

                if (! is_string($name) || ! isset($values[$name])) {
                    throw new InvalidDataSource(['values' => ['A body parameter has no value.']], ['values' => 'param-value-required']);
                }

                $used[] = $name;

                return $values[$name];
            }

            $members = [];

            foreach ($node->entries() as [$key, $member]) {
                $members[$key] = $this->fill($member, $values, $used);
            }

            return new JsonObject($members);
        }

        if (is_array($node)) {
            return array_map(function (mixed $item) use ($values, &$used): mixed {
                return $this->fill($item, $values, $used);
            }, $node);
        }

        return $node;
    }
}
