<?php

namespace App\Modules\Connector\Contracts;

/** An Endpoint with its current revision (Story 2.9). The `revision` is the number the next save is compared with. */
final readonly class Endpoint
{
    /**
     * @param  list<array{type: string, value?: string, name?: string}>  $pathAst
     * @param  list<array{name: string, binding: string, value: string|null, kind: string}>  $params  `kind` is where the parameter goes: `path`, `query` or `body`
     * @param  list<array{name: string, binding: string, value: string|null}>  $headers
     * @param  string|null  $bodyTemplate  the template as canonical JSON text, numbers as written
     * @param  array<string, string>  $testValues
     * @param  string  $createdAt  ISO 8601, UTC
     */
    public function __construct(
        public string $id,
        public string $dataSourceId,
        public int $revision,
        public string $revisionId,
        public string $method,
        public string $pathTemplate,
        public array $pathAst,
        public array $params,
        public array $headers,
        public ?string $bodyTemplate,
        public bool $readOnlyQuery,
        public string $createdAt,
        public string $updatedAt,
        public bool $requiresUserContext = false,
        public bool $scopeByCaller = false,
        /** The Admin's test values for the date-range and period rows (Story 2.14): name (`header:{name}` for a header) => ISO `YYYY-MM-DD`. */
        public array $testValues = [],
    ) {}

    /**
     * The date-range and period rows that have no test value (names, `header:{name}` for a header): a fetch key cannot be made until they do. A fixed
     * row keeps its stored value and a user-bound row has no value of its own, so neither is listed.
     *
     * @return list<string>
     */
    public function missingTestValues(): array
    {
        $missing = [];

        foreach ($this->params as $param) {
            if (self::needsTestValue($param['binding']) && ($this->testValues[$param['name']] ?? '') === '') {
                $missing[] = $param['name'];
            }
        }

        foreach ($this->headers as $header) {
            if (self::needsTestValue($header['binding']) && ($this->testValues['header:'.$header['name']] ?? '') === '') {
                $missing[] = 'header:'.$header['name'];
            }
        }

        return $missing;
    }

    /** True for the bindings that resolve to a date at fetch time (date range and period bounds). */
    public static function needsTestValue(string $binding): bool
    {
        return in_array($binding, EndpointInput::DATE_BINDINGS, true);
    }

    /** True when the parameter or header row is bound to the signed-in user's data (never a value the client may supply). */
    public static function isUserBound(string $binding): bool
    {
        return in_array($binding, EndpointInput::USER_BINDINGS, true);
    }
}
