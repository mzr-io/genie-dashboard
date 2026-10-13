<?php

namespace App\Modules\Results\Contracts;

/**
 * The rows behind a Block Result (Story 5.9; AR-32): the `rows` slots' values before top-N, kept apart from the result and named by its `rowset_ref`.
 * Cache only, every entry with a TTL: a flushed or expired set is simply gone and is made again by the next compute. Only mapped fields, measures as
 * decimal strings, never a body.
 *
 * @phpstan-type Rowset array{columns: list<array{key: string, label: string}>, values: array<string, list<string|bool|null>>}
 */
interface RowsetStore
{
    /**
     * @param  Rowset  $rowset
     * @return bool false when nothing was stored (no TTL is configured)
     */
    public function put(string $workspaceId, string $ref, array $rowset): bool;

    /** @return Rowset|null */
    public function get(string $workspaceId, string $ref): ?array;
}
