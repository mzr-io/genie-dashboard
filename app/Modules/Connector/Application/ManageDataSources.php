<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceCeilings;
use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSourcePage;
use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSourceRevisionConflict;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\DataSourceSort;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Infrastructure\DataSourceSettings;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers, edits and reads the Workspace's Data Sources (Story 2.3) inside the Workspace's transaction.
 *
 * A change checks the Base URL against the host allowlist (scheme, host and port, through {@see HostAllowlist::isAllowed})
 * and the `require_https` rule, and the name against the Workspace's other Data Sources (case-insensitively), all before
 * anything is written; it then writes the row and the audit event in the same transaction. An update locks the row,
 * compares the caller's revision (a stale one throws with the current state) and bumps it. Nothing here calls, resolves
 * or connects to any host: the allowlist is the only check, and DNS and address checks belong to fetch time.
 */
final class ManageDataSources implements DataSources
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    private const COLUMNS = 'd.id, d.name, d.base_url, d.scheme, d.host, d.port, d.auth_type, d.default_headers, d.timeout_seconds, d.max_response_bytes, d.max_pages, d.live_capable, d.revision, '
        .'to_char(d.created_at, '.self::STAMP.') AS created, to_char(d.updated_at, '.self::STAMP.') AS updated';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly HostAllowlist $allowlist,
        private readonly DataSourceSettings $settings,
    ) {}

    public function list(string $workspaceId, DataSourceQuery $query): DataSourcePage
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $query): DataSourcePage {
            $pattern = $this->pattern($query->search);
            $match = $pattern === null ? 'true' : "(lower(d.name) LIKE lower(?) ESCAPE '!' OR d.host LIKE lower(?) ESCAPE '!')";
            $bindings = $pattern === null ? [] : [$pattern, $pattern];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                "SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM data_sources d WHERE d.workspace_id = ?",
                [...$bindings, $workspaceId],
            );

            $order = $query->descending ? 'DESC' : 'ASC';
            $key = match ($query->sort) {
                DataSourceSort::Name => 'lower(d.name)',
                DataSourceSort::Host => 'd.host',
                DataSourceSort::AuthType => 'd.auth_type',
            };

            $found = DB::select(
                'SELECT '.self::COLUMNS." FROM data_sources d WHERE d.workspace_id = ? AND {$match} ORDER BY {$key} {$order}, lower(d.name) {$order}, d.id {$order}",
                [$workspaceId, ...$bindings],
            );

            return new DataSourcePage(array_values(array_map(fn (object $row): DataSource => $this->source($row), $found)), (int) $counts->total, (int) $counts->matched);
        });
    }

    public function find(string $workspaceId, string $id): DataSource
    {
        return $this->transactions->run($workspaceId, fn (): DataSource => $this->fetch($workspaceId, $id, false) ?? throw new DataSourceNotFound);
    }

    public function checkUrl(string $workspaceId, DataSourceUrl $url): void
    {
        $this->transactions->run($workspaceId, function () use ($workspaceId, $url): void {
            $refusal = $this->urlRefusal($workspaceId, $url);

            if ($refusal !== null) {
                throw new InvalidDataSource(['base_url' => [$refusal[1]]], ['base_url' => $refusal[0]]);
            }
        });
    }

    public function register(DataSourceActor $actor, DataSourceInput $input): DataSource
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $input): DataSource {
            $this->assertAcceptable($actor->workspaceId, $input, null);

            $id = (string) Str::uuid7();

            try {
                DB::insert(
                    'insert into data_sources (id, workspace_id, name, base_url, scheme, host, port, auth_type, default_headers, timeout_seconds, max_response_bytes, max_pages, live_capable, revision, created_by_membership_id, created_at, updated_at) '
                    .'values (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::boolean, 1, ?, ?, ?)',
                    [
                        $id, $actor->workspaceId, $input->name, $input->url->baseUrl, $input->url->scheme, $input->url->host, $input->url->port,
                        $input->authType, json_encode($input->headers, JSON_THROW_ON_ERROR), $input->timeoutSeconds, $input->maxResponseBytes, $input->maxPages,
                        $input->liveCapable ? 'true' : 'false', $actor->membershipId, now(), now(),
                    ],
                );
            } catch (QueryException $e) {
                throw $this->nameTaken($e);
            }

            $created = $this->fetch($actor->workspaceId, $id, false) ?? throw new DataSourceNotFound;

            $this->audit->record(
                AuditAction::ConnectorDataSourceCreated,
                $this->auditState($created),
                subject: 'data_source:'.$id,
                actor: $actor->membershipId,
            );

            return $created;
        });
    }

    public function update(DataSourceActor $actor, string $id, DataSourceInput $input, int $revision): DataSource
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $id, $input, $revision): DataSource {
            $before = $this->fetch($actor->workspaceId, $id, true) ?? throw new DataSourceNotFound;

            if ($before->revision !== $revision) {
                throw new DataSourceRevisionConflict($before);
            }

            $this->assertAcceptable($actor->workspaceId, $input, $before->id);

            try {
                DB::update(
                    'update data_sources set name = ?, base_url = ?, scheme = ?, host = ?, port = ?, auth_type = ?, default_headers = ?::jsonb, timeout_seconds = ?, max_response_bytes = ?, max_pages = ?, live_capable = ?::boolean, revision = revision + 1, updated_at = ? '
                    .'where workspace_id = ? and id = ?',
                    [
                        $input->name, $input->url->baseUrl, $input->url->scheme, $input->url->host, $input->url->port, $input->authType,
                        json_encode($input->headers, JSON_THROW_ON_ERROR), $input->timeoutSeconds, $input->maxResponseBytes, $input->maxPages,
                        $input->liveCapable ? 'true' : 'false', now(), $actor->workspaceId, $before->id,
                    ],
                );
            } catch (QueryException $e) {
                throw $this->nameTaken($e);
            }

            $after = $this->fetch($actor->workspaceId, $before->id, false) ?? throw new DataSourceNotFound;

            $this->audit->record(
                AuditAction::ConnectorDataSourceUpdated,
                $this->auditState($after),
                $this->auditState($before),
                subject: 'data_source:'.$after->id,
                actor: $actor->membershipId,
            );

            return $after;
        });
    }

    public function ceilings(): DataSourceCeilings
    {
        return $this->settings->ceilings();
    }

    /** All the checks that need the database, refused together; nothing has been written yet. */
    private function assertAcceptable(string $workspaceId, DataSourceInput $input, ?string $ownId): void
    {
        $errors = [];
        $reasons = [];

        $refusal = $this->urlRefusal($workspaceId, $input->url);

        if ($refusal !== null) {
            $errors['base_url'] = [$refusal[1]];
            $reasons['base_url'] = $refusal[0];
        }

        $taken = DB::selectOne(
            'select 1 as taken from data_sources where workspace_id = ? and lower(btrim(name)) = lower(btrim(?)) and (?::uuid is null or id <> ?::uuid) limit 1',
            [$workspaceId, $input->name, $ownId, $ownId],
        );

        if ($taken !== null) {
            $errors['name'] = ['A data source with this name already exists.'];
            $reasons['name'] = 'name-taken';
        }

        if ($errors !== []) {
            throw new InvalidDataSource($errors, $reasons);
        }
    }

    /** @return array{0: string, 1: string}|null the reason and message when the Base URL may not be used */
    private function urlRefusal(string $workspaceId, DataSourceUrl $url): ?array
    {
        if ($url->scheme === 'http' && $this->settings->requireHttps()) {
            return ['https-required', 'This workspace requires https. Use an https:// base URL.'];
        }

        if (! $this->allowlist->isAllowed($workspaceId, $url->scheme, $url->host, $url->port)) {
            return ['host-not-allowlisted', "This host isn't on your workspace allowlist."];
        }

        return null;
    }

    private function nameTaken(QueryException $e): \Throwable
    {
        // The pre-check cannot see a concurrent insert; the unique index still guards it.
        return ($e->errorInfo[0] ?? null) === '23505'
            ? new InvalidDataSource(['name' => ['A data source with this name already exists.']], ['name' => 'name-taken'])
            : $e;
    }

    private function fetch(string $workspaceId, string $id, bool $lock): ?DataSource
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT '.self::COLUMNS.' FROM data_sources d WHERE d.workspace_id = ? AND d.id = ?'.($lock ? ' FOR UPDATE OF d' : ''),
            [$workspaceId, strtolower($id)],
        );

        return $row === null ? null : $this->source($row);
    }

    /** The allowlisted audit fields: the URL's parts, never the full URL path; the header count and a keyed hash of the header map, never a value. */
    /** @return array<string, mixed> */
    private function auditState(DataSource $source): array
    {
        $headers = $source->headers;
        usort($headers, fn (array $a, array $b): int => strcmp(strtolower($a['name']), strtolower($b['name'])));

        return [
            'data_source_id' => $source->id,
            'scheme' => $source->scheme,
            'host' => $source->host,
            'port' => $source->port,
            'auth_type' => $source->authType,
            'timeout_seconds' => $source->timeoutSeconds,
            'max_response_bytes' => $source->maxResponseBytes,
            'max_pages' => $source->maxPages,
            'live_capable' => $source->liveCapable ? 'true' : 'false',
            'header_count' => count($headers),
            'headers' => json_encode(array_map(fn (array $h): array => [strtolower($h['name']), $h['value']], $headers), JSON_THROW_ON_ERROR),
            'revision' => $source->revision,
        ];
    }

    private function source(object $row): DataSource
    {
        /** @var object{id: string, name: string, base_url: string, scheme: string, host: string, port: int|string, auth_type: string, default_headers: string, timeout_seconds: int|string|null, max_response_bytes: int|string|null, max_pages: int|string|null, live_capable: bool|string|int, revision: int|string, created: string, updated: string} $row */
        $decoded = json_decode($row->default_headers, true);
        $headers = [];

        foreach (is_array($decoded) ? $decoded : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null)) {
                $headers[] = ['name' => $header['name'], 'value' => $header['value']];
            }
        }

        return new DataSource(
            strtolower($row->id),
            $row->name,
            $row->base_url,
            $row->scheme,
            $row->host,
            (int) $row->port,
            $row->auth_type,
            $headers,
            $row->timeout_seconds === null ? null : (int) $row->timeout_seconds,
            $row->max_response_bytes === null ? null : (int) $row->max_response_bytes,
            $row->max_pages === null ? null : (int) $row->max_pages,
            filter_var($row->live_capable, FILTER_VALIDATE_BOOLEAN),
            (int) $row->revision,
            $row->created,
            $row->updated,
        );
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
