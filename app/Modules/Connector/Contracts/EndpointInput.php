<?php

namespace App\Modules\Connector\Contracts;

/**
 * The validated fields of an Endpoint revision (Story 2.9), ready to store. Parameters and headers are
 * `{name, binding, value}` rows (the value is null for a date or period binding); the body template is the stored tree, in which
 * a parameter is `{"$param": name}` and a number is `{"$number": lexeme}` so that no digit is lost.
 */
final readonly class EndpointInput
{
    public const BINDINGS = ['fixed', 'date_range_from', 'date_range_to', 'period_start', 'period_end'];

    public const METHODS = ['GET', 'POST'];

    /**
     * @param  list<array{name: string, binding: string, value: string|null, kind: string}>  $params  `kind` is `path`, `query` or `body`
     * @param  list<array{name: string, binding: string, value: string|null}>  $headers
     */
    public function __construct(
        public string $method,
        public EndpointPath $path,
        public array $params,
        public array $headers,
        public mixed $bodyTemplate,
        public bool $readOnlyQuery,
    ) {}
}
