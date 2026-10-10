<?php

namespace App\Modules\Datasets\Contracts;

/**
 * The suggestion catalog for new Drafts (Story 3.11c). A Dataset is `(workspace, endpoint, record_path)` with its typed fields. It is only ever a
 * suggestion: a Draft's own `config` stays authoritative, nothing is applied from it, and runtime never reads it.
 */
interface DatasetSuggestions
{
    /**
     * Upserts the Dataset of the Endpoint and record path and replaces its fields; idempotent. Runs in the caller's Workspace transaction.
     *
     * @param  list<array{path: string, type: string, role: string|null}>  $fields
     */
    public function remember(string $workspaceId, string $endpointId, string $recordPath, array $fields): void;

    /** The most recently updated Dataset of the Endpoint in the Workspace, or null. */
    public function suggestFor(string $workspaceId, string $endpointId): ?DatasetSuggestion;
}
