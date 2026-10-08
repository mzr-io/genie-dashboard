<?php

namespace App\Modules\Connector\Contracts;

/**
 * The validated fields of an Endpoint revision (Story 2.9), ready to store. Parameters and headers are
 * `{name, binding, value}` rows (the value is null for a date, period, user ID, email or group binding, and the attribute key id for `user_attribute`); the body template is the stored tree, in which
 * a parameter is `{"$param": name}` and a number is `{"$number": lexeme}` so that no digit is lost.
 */
final readonly class EndpointInput
{
    /** The bindings that read the signed-in user's own data (Story 2.13); `user_attribute` takes a defined attribute key id as its value. */
    public const USER_BINDINGS = ['user_id', 'user_email', 'user_group', 'user_attribute'];

    public const BINDINGS = ['fixed', 'date_range_from', 'date_range_to', 'period_start', 'period_end', 'user_id', 'user_email', 'user_group', 'user_attribute'];

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
        public bool $scopeByCaller = false,
    ) {}

    /** Derived, never supplied: true when any parameter or header uses a user-context binding. */
    public function requiresUserContext(): bool
    {
        foreach ([...$this->params, ...$this->headers] as $row) {
            if (in_array($row['binding'], self::USER_BINDINGS, true)) {
                return true;
            }
        }

        return false;
    }
}
