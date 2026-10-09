<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * What a fetch key is made of (Story 2.14; AD-7): the Workspace, the Endpoint revision, the Data Source revision, the typed parameters and
 * the context. Nothing else may change which response a key names.
 *
 * `params` maps a parameter name (`header:{name}` for a header) to `{t, v}`: `t` is `string`, `number`, `bool`, `date` or `datetime` and `v`
 * is always a JSON string (a number is its lexeme, a bool is `true` or `false`, a date is the Workspace-local `YYYY-MM-DD`, a datetime is UTC
 * `...Z`). An absent binding is `null` and is left out.
 *
 * `bound` is null for shared data. For user-bound data it maps each user-binding reference (the parameter name, `header:{name}` for a header)
 * to the value resolved for the member, which must be a non-empty string; `scopeByCaller` adds the member's ID to the context, so two members with
 * identical attributes do not share a fetch.
 */
final readonly class FetchKeyInput
{
    public const TYPES = ['string', 'number', 'bool', 'date', 'datetime'];

    /**
     * @param  array<string, array{t: string, v: string}|null>  $params
     * @param  array<string, mixed>|null  $bound
     */
    public function __construct(
        public string $workspaceId,
        public string $endpointRevisionId,
        public int $dataSourceRevision,
        public array $params,
        public ?array $bound = null,
        public bool $scopeByCaller = false,
        public ?string $membershipId = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpoint_revision_id' => $this->endpointRevisionId, 'data_source_revision' => $this->dataSourceRevision, 'shared' => $this->bound === null];
    }
}
