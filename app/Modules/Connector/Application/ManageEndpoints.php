<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\EndpointPage;
use App\Modules\Connector\Contracts\EndpointRevisionConflict;
use App\Modules\Connector\Contracts\Endpoints;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers and revises the Endpoints of a Data Source (Story 2.9) inside the Workspace's transaction.
 *
 * `endpoints` holds the Endpoint and a pointer to its current revision; `endpoint_revisions` holds one immutable row per
 * save. Creating writes the Endpoint, revision 1 and the pointer together and audits `connector.endpoint.created`; a later save
 * locks the Endpoint, compares the caller's `revision` (a stale one throws with the current state), writes revision + 1,
 * moves the pointer and audits `connector.endpoint.revised`, all in one transaction. An older revision is never touched
 * (the table refuses it) and the Data Source's own `revision` is not changed. The first time a revision sets the read-only
 * flag, `connector.endpoint.read_only_flag_set` is audited in the same transaction. The audit state is ids, the method,
 * revision numbers, counts and keyed hashes of the path and bindings, never the path, a value or a header in the clear.
 * Nothing here sends a request.
 */
final class ManageEndpoints implements Endpoints
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    private const SELECT = 'SELECT e.id, e.data_source_id, e.revision, e.current_revision_id, r.method, r.path_template, r.path_ast, r.params, r.headers, r.body_template, r.read_only_query, '
        .'to_char(e.created_at, '.self::STAMP.') AS created, to_char(e.updated_at, '.self::STAMP.') AS updated '
        .'FROM endpoints e JOIN endpoint_revisions r ON r.workspace_id = e.workspace_id AND r.id = e.current_revision_id';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
    ) {}

    public function list(string $workspaceId, string $dataSourceId, ?string $search): EndpointPage
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $dataSourceId, $search): EndpointPage {
            $this->assertDataSource($workspaceId, $dataSourceId, false);

            $term = $this->term($search);
            $match = $term === '' ? 'true' : "(lower(r.path_template) LIKE lower(?) ESCAPE '!' OR r.method = upper(?))";
            $bindings = $term === '' ? [] : ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%', $term];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                "SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM endpoints e JOIN endpoint_revisions r ON r.workspace_id = e.workspace_id AND r.id = e.current_revision_id WHERE e.workspace_id = ? AND e.data_source_id = ?",
                [...$bindings, $workspaceId, strtolower($dataSourceId)],
            );

            $found = DB::select(
                self::SELECT." WHERE e.workspace_id = ? AND e.data_source_id = ? AND {$match} ORDER BY r.path_template ASC, r.method ASC, e.id ASC",
                [$workspaceId, strtolower($dataSourceId), ...$bindings],
            );

            return new EndpointPage(array_values(array_map(fn (object $row): Endpoint => $this->endpoint($row), $found)), (int) $counts->total, (int) $counts->matched);
        });
    }

    public function find(string $workspaceId, string $dataSourceId, string $id): Endpoint
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $dataSourceId, $id): Endpoint {
            $this->assertDataSource($workspaceId, $dataSourceId, false);

            return $this->fetch($workspaceId, $dataSourceId, $id, false) ?? throw new EndpointNotFound;
        });
    }

    public function create(DataSourceActor $actor, string $dataSourceId, EndpointInput $input): Endpoint
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $dataSourceId, $input): Endpoint {
            $this->assertDataSource($actor->workspaceId, $dataSourceId, true);

            $id = (string) Str::uuid7();
            $revisionId = (string) Str::uuid7();

            // The pointer's foreign key is checked at commit, so the Endpoint can name the revision written right after it.
            DB::insert(
                'insert into endpoints (id, workspace_id, data_source_id, revision, current_revision_id, created_by_membership_id, created_at, updated_at) values (?, ?, ?, 1, ?, ?, ?, ?)',
                [$id, $actor->workspaceId, strtolower($dataSourceId), $revisionId, $actor->membershipId, now(), now()],
            );
            $this->insertRevision($actor, $id, $revisionId, 1, $input);

            $created = $this->fetch($actor->workspaceId, $dataSourceId, $id, false) ?? throw new EndpointNotFound;

            $this->audit->record(
                AuditAction::ConnectorEndpointCreated,
                $this->auditState($created),
                subject: 'endpoint:'.$id,
                actor: $actor->membershipId,
            );

            if ($created->readOnlyQuery) {
                $this->auditFlag($actor, $created);
            }

            return $created;
        });
    }

    public function revise(DataSourceActor $actor, string $dataSourceId, string $id, EndpointInput $input, int $revision): Endpoint
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $dataSourceId, $id, $input, $revision): Endpoint {
            $this->assertDataSource($actor->workspaceId, $dataSourceId, false);

            $before = $this->fetch($actor->workspaceId, $dataSourceId, $id, true) ?? throw new EndpointNotFound;

            if ($before->revision !== $revision) {
                throw new EndpointRevisionConflict($before);
            }

            $next = $before->revision + 1;
            $revisionId = (string) Str::uuid7();

            $this->insertRevision($actor, $before->id, $revisionId, $next, $input);
            DB::update(
                'update endpoints set revision = ?, current_revision_id = ?, updated_at = ? where workspace_id = ? and id = ?',
                [$next, $revisionId, now(), $actor->workspaceId, $before->id],
            );

            $after = $this->fetch($actor->workspaceId, $dataSourceId, $before->id, false) ?? throw new EndpointNotFound;

            $this->audit->record(
                AuditAction::ConnectorEndpointRevised,
                $this->auditState($after),
                $this->auditState($before),
                subject: 'endpoint:'.$after->id,
                actor: $actor->membershipId,
            );

            if ($after->readOnlyQuery && ! $before->readOnlyQuery) {
                $this->auditFlag($actor, $after);
            }

            return $after;
        });
    }

    private function insertRevision(DataSourceActor $actor, string $endpointId, string $revisionId, int $number, EndpointInput $input): void
    {
        DB::insert(
            'insert into endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, params, headers, body_template, read_only_query, created_at, created_by_membership_id) '
            .'values (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?::boolean, ?, ?)',
            [
                $revisionId, $actor->workspaceId, $endpointId, $number, $input->method, $input->path->template,
                json_encode($input->path->ast, JSON_THROW_ON_ERROR),
                json_encode($input->params, JSON_THROW_ON_ERROR),
                json_encode($input->headers, JSON_THROW_ON_ERROR),
                $input->bodyTemplate === null ? null : json_encode($input->bodyTemplate, JSON_THROW_ON_ERROR),
                $input->readOnlyQuery ? 'true' : 'false', now(), $actor->membershipId,
            ],
        );
    }

    /** The Data Source must be in the Workspace (row-level security hides the others); a lock keeps it from vanishing mid-write. */
    private function assertDataSource(string $workspaceId, string $dataSourceId, bool $lock): void
    {
        if (! Str::isUuid($dataSourceId)) {
            throw new DataSourceNotFound;
        }

        $row = DB::selectOne(
            'select id from data_sources where workspace_id = ? and id = ?'.($lock ? ' for key share' : ''),
            [$workspaceId, strtolower($dataSourceId)],
        );

        if ($row === null) {
            throw new DataSourceNotFound;
        }
    }

    private function fetch(string $workspaceId, string $dataSourceId, string $id, bool $lock): ?Endpoint
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if ($lock) {
            // Lock the Endpoint row alone, then read it: after waiting for another save, the read sees the committed revision.
            $locked = DB::selectOne(
                'select id from endpoints where workspace_id = ? and data_source_id = ? and id = ? for update',
                [$workspaceId, strtolower($dataSourceId), strtolower($id)],
            );

            if ($locked === null) {
                return null;
            }
        }

        $row = DB::selectOne(
            self::SELECT.' WHERE e.workspace_id = ? AND e.data_source_id = ? AND e.id = ?',
            [$workspaceId, strtolower($dataSourceId), strtolower($id)],
        );

        return $row === null ? null : $this->endpoint($row);
    }

    private function auditFlag(DataSourceActor $actor, Endpoint $endpoint): void
    {
        $this->audit->record(
            AuditAction::ConnectorEndpointReadOnlyFlagSet,
            $this->auditState($endpoint),
            subject: 'endpoint:'.$endpoint->id,
            actor: $actor->membershipId,
        );
    }

    /**
     * The allowlisted audit fields: ids, the method, the revision number, counts, and keyed hashes of the path and of the
     * bindings (parameters, headers and body template); never the path, a value or a header in the clear.
     *
     * @return array<string, mixed>
     */
    private function auditState(Endpoint $endpoint): array
    {
        $headers = $endpoint->headers;
        usort($headers, fn (array $a, array $b): int => strcmp(strtolower($a['name']), strtolower($b['name'])));
        $params = $endpoint->params;
        usort($params, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'endpoint_id' => $endpoint->id,
            'data_source_id' => $endpoint->dataSourceId,
            'method' => strtolower($endpoint->method),
            'revision' => $endpoint->revision,
            'param_count' => count($params),
            'header_count' => count($headers),
            'read_only_query' => $endpoint->readOnlyQuery ? 'true' : 'false',
            'path' => $endpoint->pathTemplate,
            'bindings' => json_encode([
                array_map(fn (array $p): array => [$p['name'], $p['binding'], $p['value']], $params),
                array_map(fn (array $h): array => [strtolower($h['name']), $h['binding'], $h['value']], $headers),
                $endpoint->bodyTemplate,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function endpoint(object $row): Endpoint
    {
        /** @var object{id: string, data_source_id: string, revision: int|string, current_revision_id: string, method: string, path_template: string, path_ast: string, params: string, headers: string, body_template: string|null, read_only_query: bool|string|int, created: string, updated: string} $row */
        $params = [];

        foreach ($this->asList(json_decode($row->params, true)) as $p) {
            if (is_array($p) && is_string($p['name'] ?? null) && is_string($p['binding'] ?? null)) {
                $params[] = ['name' => $p['name'], 'binding' => $p['binding'], 'value' => is_string($p['value'] ?? null) ? $p['value'] : null, 'kind' => is_string($p['kind'] ?? null) ? $p['kind'] : 'query'];
            }
        }

        $headers = [];

        foreach ($this->asList(json_decode($row->headers, true)) as $h) {
            if (is_array($h) && is_string($h['name'] ?? null) && is_string($h['binding'] ?? null)) {
                $headers[] = ['name' => $h['name'], 'binding' => $h['binding'], 'value' => is_string($h['value'] ?? null) ? $h['value'] : null];
            }
        }

        $ast = [];

        foreach ($this->asList(json_decode($row->path_ast, true)) as $segment) {
            if (is_array($segment) && is_string($segment['type'] ?? null)) {
                $ast[] = array_filter(['type' => $segment['type'], 'value' => $segment['value'] ?? null, 'name' => $segment['name'] ?? null], fn (mixed $v): bool => $v !== null);
            }
        }

        return new Endpoint(
            strtolower($row->id),
            strtolower($row->data_source_id),
            (int) $row->revision,
            strtolower($row->current_revision_id),
            $row->method,
            $row->path_template,
            $ast,
            $params,
            $headers,
            $row->body_template === null ? null : EndpointBodyTemplate::text(json_decode($row->body_template)),
            filter_var($row->read_only_query, FILTER_VALIDATE_BOOLEAN),
            $row->created,
            $row->updated,
        );
    }

    /** @return list<mixed> */
    private function asList(mixed $decoded): array
    {
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** The search term without NUL bytes or invalid UTF-8; empty when there is nothing to search. */
    private function term(?string $search): string
    {
        return trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));
    }
}
