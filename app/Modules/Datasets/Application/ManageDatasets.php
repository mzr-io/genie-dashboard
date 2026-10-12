<?php

namespace App\Modules\Datasets\Application;

use App\Modules\Datasets\Contracts\DatasetSuggestion;
use App\Modules\Datasets\Contracts\DatasetSuggestions;
use App\Platform\Json\SamplePath;
use App\Platform\Tenancy\WorkspaceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The suggestion catalog (Story 3.11c). Both tables are tenant tables under row-level security, so another Workspace's rows are never visible. Role
 * `app` cannot DELETE: a Dataset's field list is replaced by rewriting its rows in place in `position` order, inserting rows for extra fields and
 * retiring the surplus (position null), which a later list reuses. Only paths, types and roles are stored.
 */
final class ManageDatasets implements DatasetSuggestions
{
    private const ROLES = ['label', 'value', 'dimension', 'measure', 'time', 'filter'];

    private const PATH_MAX = 512;

    private const TYPE_MAX = 64;

    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    /**
     * Best-effort: the catalog is only a suggestion, so it never fails or poisons the caller's write. The input is checked first (an Endpoint id that is
     * not a UUID, a path outside the stored subset or too long, a field whose path or type does not fit its column is skipped, an unknown role becomes
     * null), and the writes run in a savepoint: a refusal of the database (a removed Endpoint's foreign key, a check) rolls back only the catalog and
     * is logged by its class alone.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public function remember(string $workspaceId, string $endpointId, string $recordPath, array $fields): void
    {
        if (! Str::isUuid($endpointId) || ! self::path($recordPath)) {
            return;
        }

        $clean = [];

        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');

            if (! is_string($field['path'] ?? null) || ! self::path($field['path']) || $type === '' || mb_strlen($type) > self::TYPE_MAX) {
                continue;
            }

            $role = $field['role'] ?? null;
            $clean[] = ['path' => $field['path'], 'type' => $type, 'role' => is_string($role) && in_array($role, self::ROLES, true) ? $role : null];
        }

        try {
            $this->transactions->run($workspaceId, fn () => DB::transaction(fn () => $this->write($workspaceId, strtolower($endpointId), $recordPath, $clean)));
        } catch (\Throwable $e) {
            Log::warning('The dataset suggestion was not remembered.', ['exception' => $e::class]);
        }
    }

    private static function path(string $path): bool
    {
        if (strlen($path) > self::PATH_MAX) {
            return false;
        }

        try {
            SamplePath::parse($path);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** @param  list<array{path: string, type: string, role: string|null}>  $fields */
    private function write(string $workspaceId, string $endpointId, string $recordPath, array $fields): void
    {
        // The unique key makes the upsert one statement; a concurrent first pick of the same path cannot make two rows.
        $row = DB::selectOne(
            'insert into datasets (id, workspace_id, endpoint_id, record_path, created_at, updated_at) values (?, ?, ?, ?, clock_timestamp(), clock_timestamp())
             on conflict (workspace_id, endpoint_id, record_path) do update set updated_at = clock_timestamp()
             returning id',
            [(string) Str::uuid7(), $workspaceId, $endpointId, $recordPath],
        );
        $datasetId = (string) $row->id;

        // Active rows first (their positions are 0..m-1, so rewriting row i to position i never collides), then retired rows, which get the positions after.
        $existing = DB::select('select id, position from dataset_fields where workspace_id = ? and dataset_id = ? order by position asc nulls last, id asc', [$workspaceId, $datasetId]);

        foreach ($fields as $index => $field) {
            $values = [$field['path'], $field['type'], $field['role'], $index];

            if (isset($existing[$index])) {
                DB::update('update dataset_fields set path = ?, type = ?, role = ?, position = ? where workspace_id = ? and id = ?', [...$values, $workspaceId, $existing[$index]->id]);
            } else {
                DB::insert('insert into dataset_fields (id, workspace_id, dataset_id, path, type, role, position) values (?, ?, ?, ?, ?, ?, ?)', [(string) Str::uuid7(), $workspaceId, $datasetId, ...$values]);
            }
        }

        foreach (array_slice($existing, count($fields)) as $surplus) {
            if ($surplus->position !== null) {
                DB::update('update dataset_fields set position = null where workspace_id = ? and id = ?', [$workspaceId, $surplus->id]);
            }
        }
    }

    public function suggestFor(string $workspaceId, string $endpointId): ?DatasetSuggestion
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $endpointId): ?DatasetSuggestion {
            if (! Str::isUuid($endpointId)) {
                return null;
            }

            $dataset = DB::selectOne(
                'select d.id, d.record_path, d.updated_at from datasets d where d.workspace_id = ? and d.endpoint_id = ?
                 and exists (select 1 from dataset_fields f where f.workspace_id = d.workspace_id and f.dataset_id = d.id and f.position is not null)
                 order by d.updated_at desc, d.id desc limit 1',
                [$workspaceId, strtolower($endpointId)],
            );

            if ($dataset === null) {
                return null;
            }

            $fields = [];

            foreach (DB::select('select path, type, role from dataset_fields where workspace_id = ? and dataset_id = ? and position is not null order by position', [$workspaceId, $dataset->id]) as $row) {
                $row = (array) $row;
                $fields[] = ['path' => (string) $row['path'], 'type' => (string) $row['type'], 'role' => $row['role'] === null ? null : (string) $row['role']];
            }

            return new DatasetSuggestion((string) $dataset->record_path, $fields, CarbonImmutable::parse((string) $dataset->updated_at)->utc()->format('Y-m-d\TH:i:s.u\Z'));
        });
    }
}
