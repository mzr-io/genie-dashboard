<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\ConfirmationRefused;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceCeilings;
use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSourceLockLost;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSourcePage;
use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSourceRevisionConflict;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\DataSourceSort;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\Pagination;
use App\Modules\Connector\Contracts\SealedSecret;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretStatus;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Infrastructure\DataSourceSettings;
use App\Modules\Connector\Infrastructure\OAuthTokenCache;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
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

    private const COLUMNS = 'd.id, d.name, d.base_url, d.scheme, d.host, d.port, d.auth_type, d.default_headers, d.timeout_seconds, d.max_response_bytes, d.max_pages, d.live_capable, d.api_key_name, d.api_key_placement, d.oauth_token_url, d.oauth_client_id, d.oauth_scope, d.pagination_style, d.pagination_param, d.pagination_size_param, d.pagination_size, d.pagination_records_path, d.pagination_cursor_path, d.revision, d.lock_epoch, '
        .'to_char(d.created_at, '.self::STAMP.') AS created, to_char(d.updated_at, '.self::STAMP.') AS updated';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly HostAllowlist $allowlist,
        private readonly DataSourceSettings $settings,
        private readonly SecretVault $vault,
        private readonly OAuthTokenCache $tokenCache,
        private readonly Outbox $outbox,
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

    public function checkUrl(string $workspaceId, DataSourceUrl $url, string $field = 'base_url'): void
    {
        $this->transactions->run($workspaceId, function () use ($workspaceId, $url, $field): void {
            $refusal = $this->urlRefusal($workspaceId, $url, $field === 'base_url' ? 'base' : 'token');

            if ($refusal !== null) {
                throw new InvalidDataSource([$field => [$refusal[1]]], [$field => $refusal[0]]);
            }
        });
    }

    public function register(DataSourceActor $actor, #[\SensitiveParameter] DataSourceInput $input, ?\Closure $confirm = null): DataSource
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $input, $confirm): DataSource {
            $this->assertAcceptable($actor->workspaceId, $input, null);

            $id = (string) Str::uuid7();
            $plan = $this->plan($input, []);
            $this->confirm($plan, $input->authType !== 'none', $confirm);
            $sealed = $this->seal($actor->workspaceId, $id, $plan);

            try {
                DB::insert(
                    'insert into data_sources (id, workspace_id, name, base_url, scheme, host, port, auth_type, api_key_name, api_key_placement, oauth_token_url, oauth_client_id, oauth_scope, default_headers, timeout_seconds, max_response_bytes, max_pages, live_capable, pagination_style, pagination_param, pagination_size_param, pagination_size, pagination_records_path, pagination_cursor_path, revision, created_by_membership_id, created_at, updated_at) '
                    .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?::boolean, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                    [
                        $id, $actor->workspaceId, $input->name, $input->url->baseUrl, $input->url->scheme, $input->url->host, $input->url->port,
                        $input->authType, $input->apiKeyName, $input->apiKeyPlacement, $input->oauthTokenUrl?->baseUrl, $input->oauthClientId, $input->oauthScope,
                        json_encode($this->storedHeaders($input->headers), JSON_THROW_ON_ERROR), $input->timeoutSeconds, $input->maxResponseBytes, $input->maxPages,
                        $input->liveCapable ? 'true' : 'false', ...$this->paginationValues($input->pagination), $actor->membershipId, now(), now(),
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

            $this->storeSecrets($actor, $id, $plan, $sealed, []);

            return $this->fetch($actor->workspaceId, $id, false) ?? throw new DataSourceNotFound;
        });
    }

    public function update(DataSourceActor $actor, string $id, #[\SensitiveParameter] DataSourceInput $input, int $revision, ?\Closure $confirm = null, ?int $lockEpoch = null, ?\Closure $holdsLock = null): DataSource
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $id, $input, $revision, $confirm, $lockEpoch, $holdsLock): DataSource {
            $before = $this->fetch($actor->workspaceId, $id, true) ?? throw new DataSourceNotFound;

            // The edit lock first (Story 2.8), before anything is sealed or written: a stale epoch, or a token that no longer
            // holds the lock, is refused with the current state, so a lost holder never stores a secret. It outranks the
            // revision check because a take-over saves the holder's work first and so raises the revision as well.
            if (($lockEpoch !== null && $before->lockEpoch !== $lockEpoch) || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DataSourceLockLost($before);
            }

            if ($before->revision !== $revision) {
                throw new DataSourceRevisionConflict($before);
            }

            $this->assertAcceptable($actor->workspaceId, $input, $before->id);

            $plan = $this->plan($input, $before->secrets);
            // A credential already stored must not be rerouted (another header, name or the query string) without the password.
            $rerouted = ($before->secrets !== [] && ($input->apiKeyName !== $before->apiKeyName || $input->apiKeyPlacement !== $before->apiKeyPlacement))
                // The same for the client secret: another token URL or client ID would send it somewhere else (Story 2.7).
                || (isset($before->secrets[SecretSlots::OAUTH_CLIENT_SECRET]) && ($input->oauthTokenUrl?->baseUrl !== $before->oauthTokenUrl || $input->oauthClientId !== $before->oauthClientId));
            $this->confirm($plan, $input->authType !== $before->authType || $rerouted, $confirm);
            $sealed = $this->seal($actor->workspaceId, $before->id, $plan);

            try {
                DB::update(
                    'update data_sources set name = ?, base_url = ?, scheme = ?, host = ?, port = ?, auth_type = ?, api_key_name = ?, api_key_placement = ?, oauth_token_url = ?, oauth_client_id = ?, oauth_scope = ?, default_headers = ?::jsonb, timeout_seconds = ?, max_response_bytes = ?, max_pages = ?, live_capable = ?::boolean, pagination_style = ?, pagination_param = ?, pagination_size_param = ?, pagination_size = ?, pagination_records_path = ?, pagination_cursor_path = ?, revision = revision + 1, updated_at = ? '
                    .'where workspace_id = ? and id = ?',
                    [
                        $input->name, $input->url->baseUrl, $input->url->scheme, $input->url->host, $input->url->port, $input->authType, $input->apiKeyName, $input->apiKeyPlacement, $input->oauthTokenUrl?->baseUrl, $input->oauthClientId, $input->oauthScope,
                        json_encode($this->storedHeaders($input->headers), JSON_THROW_ON_ERROR), $input->timeoutSeconds, $input->maxResponseBytes, $input->maxPages,
                        $input->liveCapable ? 'true' : 'false', ...$this->paginationValues($input->pagination), now(), $actor->workspaceId, $before->id,
                    ],
                );
            } catch (QueryException $e) {
                throw $this->nameTaken($e);
            }

            $this->storeSecrets($actor, $before->id, $plan, $sealed, $before->secrets);

            $after = $this->fetch($actor->workspaceId, $before->id, false) ?? throw new DataSourceNotFound;

            $this->audit->record(
                AuditAction::ConnectorDataSourceUpdated,
                $this->auditState($after),
                $this->auditState($before),
                subject: 'data_source:'.$after->id,
                actor: $actor->membershipId,
            );

            // Every Endpoint's fetch key names the Data Source revision (Story 2.14): Ingestion re-registers its targets from this. IDs and the number only.
            $this->outbox->emit(AuditAction::ConnectorDataSourceUpdated, 'data_source:'.$after->id, [
                'data_source_id' => $after->id,
                'revision' => $after->revision,
            ], actor: $actor->membershipId);

            return $after;
        });
    }

    public function ceilings(): DataSourceCeilings
    {
        return $this->settings->ceilings();
    }

    /** All the checks that need the database, refused together; nothing has been written yet. */
    private function assertAcceptable(string $workspaceId, #[\SensitiveParameter] DataSourceInput $input, ?string $ownId): void
    {
        $errors = [];
        $reasons = [];

        $refusal = $this->urlRefusal($workspaceId, $input->url);

        if ($refusal !== null) {
            $errors['base_url'] = [$refusal[1]];
            $reasons['base_url'] = $refusal[0];
        }

        if ($input->oauthTokenUrl !== null) {
            $refusal = $this->urlRefusal($workspaceId, $input->oauthTokenUrl, 'token');

            if ($refusal !== null) {
                $errors['oauth_token_url'] = [$refusal[1]];
                $reasons['oauth_token_url'] = $refusal[0];
            }
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
    private function urlRefusal(string $workspaceId, DataSourceUrl $url, string $noun = 'base'): ?array
    {
        if ($url->scheme === 'http' && $this->settings->requireHttps()) {
            return ['https-required', "This workspace requires https. Use an https:// {$noun} URL."];
        }

        if (! $this->allowlist->isAllowed($workspaceId, $url->scheme, $url->host, $url->port)) {
            return ['host-not-allowlisted', "This host isn't on your workspace allowlist."];
        }

        return null;
    }

    /**
     * What the change does to the secret slots. The slots in use are those of the auth type plus one per secret default
     * header; every one must hold a value (saved, or given now). A slot no longer in use is removed. Nothing is written.
     *
     * @param  array<string, SecretStatus>  $existing
     * @return array{set: array<string, string>, remove: list<string>, kinds: array<string, string>}
     */
    private function plan(#[\SensitiveParameter] DataSourceInput $input, array $existing): array
    {
        $used = SecretSlots::forAuth($input->authType);
        $rows = [];

        foreach ($input->headers as $i => $header) {
            if (($header['secret'] ?? false) === true) {
                $slot = SecretSlots::header($header['name']);
                $used[] = $slot;
                $rows[$slot] = $i;
            }
        }

        $errors = [];
        $reasons = [];

        foreach ($used as $slot) {
            if (! isset($existing[$slot]) && ! isset($input->secretValues[$slot])) {
                $field = isset($rows[$slot]) ? "headers.{$rows[$slot]}.value" : "secrets.{$slot}";
                $errors[$field] = ['Enter a value.'];
                $reasons[$field] = 'secret-required';
            }
        }

        if ($errors !== []) {
            throw new InvalidDataSource($errors, $reasons);
        }

        $set = array_intersect_key($input->secretValues, array_flip($used));
        $remove = array_values(array_diff(array_keys($existing), $used));
        $kinds = [];

        foreach ($set as $slot => $_) {
            $kinds[$slot] = isset($existing[$slot]) ? 'replaced' : 'set';
        }

        foreach ($remove as $slot) {
            $kinds[$slot] = 'removed';
        }

        return ['set' => $set, 'remove' => $remove, 'kinds' => $kinds];
    }

    /**
     * Setting, replacing or removing a secret, or changing the auth type, needs the Admin's password; the caller's closure
     * checks it. Nothing has been written yet.
     *
     * @param  array{set: array<string, string>, remove: list<string>, kinds: array<string, string>}  $plan
     */
    private function confirm(#[\SensitiveParameter] array $plan, bool $authChanged, ?\Closure $confirm): void
    {
        if (($plan['kinds'] !== [] || $authChanged) && ($confirm === null || $confirm() !== true)) {
            throw new ConfirmationRefused;
        }
    }

    /**
     * Seals every value to be stored before anything is written, so an unset platform key refuses the whole change.
     *
     * @param  array{set: array<string, string>, remove: list<string>, kinds: array<string, string>}  $plan
     * @return array<string, SealedSecret>
     */
    private function seal(string $workspaceId, string $dataSourceId, #[\SensitiveParameter] array $plan): array
    {
        $sealed = [];

        foreach ($plan['set'] as $slot => $value) {
            $sealed[$slot] = $this->vault->seal(new SecretContext($workspaceId, $dataSourceId, $slot), $value);
        }

        return $sealed;
    }

    /**
     * Writes the sealed values and removes the slots no longer used (through the `SECURITY DEFINER` function: `app` cannot
     * delete), auditing each change with the value's keyed hash only.
     *
     * @param  array{set: array<string, string>, remove: list<string>, kinds: array<string, string>}  $plan
     * @param  array<string, SealedSecret>  $sealed
     * @param  array<string, SecretStatus>  $existing
     */
    private function storeSecrets(DataSourceActor $actor, string $dataSourceId, #[\SensitiveParameter] array $plan, #[\SensitiveParameter] array $sealed, array $existing): void
    {
        foreach ($sealed as $slot => $secret) {
            $encoded = base64_encode($secret->ciphertext);

            if (isset($existing[$slot])) {
                DB::update(
                    "update secrets set ciphertext = decode(?, 'base64'), key_version = ?, key_ref = ?, version = version + 1, updated_at = ? where workspace_id = ? and data_source_id = ? and slot = ?",
                    [$encoded, $secret->keyVersion, $secret->keyRef, now(), $actor->workspaceId, $dataSourceId, $slot],
                );
            } else {
                DB::insert(
                    "insert into secrets (id, workspace_id, data_source_id, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, ?, ?, 'cred', ?, ?, decode(?, 'base64'), ?, ?)",
                    [(string) Str::uuid7(), $actor->workspaceId, $dataSourceId, $slot, $secret->keyVersion, $secret->keyRef, $encoded, now(), now()],
                );
            }

            $this->audit->record(
                AuditAction::ConnectorDataSourceSecretChanged,
                [
                    'data_source_id' => $dataSourceId,
                    'slot' => SecretSlots::kind($slot),
                    'purpose' => SecretContext::PURPOSE_CRED,
                    'key_version' => $secret->keyVersion,
                    'action' => $plan['kinds'][$slot],
                    'value_hash' => $plan['set'][$slot],
                ],
                subject: 'data_source:'.$dataSourceId,
                actor: $actor->membershipId,
            );
        }

        if ($plan['remove'] !== []) {
            $literal = '{'.implode(',', array_map(fn (string $slot): string => '"'.addcslashes($slot, '"\\').'"', $plan['remove'])).'}';
            DB::selectOne('select connector_remove_secrets(?::uuid, ?::text[]) as removed', [$dataSourceId, $literal]);

            foreach ($plan['remove'] as $slot) {
                if ($slot === SecretSlots::OAUTH_CLIENT_SECRET && isset($existing[$slot])) {
                    // The version restarts at 1 if the secret is set again: a token cached under the old one must not outlive it.
                    $this->tokenCache->forget($actor->workspaceId, $dataSourceId, $existing[$slot]->secretVersion ?? 1);
                }

                $this->audit->record(
                    AuditAction::ConnectorDataSourceSecretChanged,
                    [
                        'data_source_id' => $dataSourceId,
                        'slot' => SecretSlots::kind($slot),
                        'purpose' => SecretContext::PURPOSE_CRED,
                        'key_version' => $existing[$slot]->keyVersion,
                        'action' => 'removed',
                    ],
                    subject: 'data_source:'.$dataSourceId,
                    actor: $actor->membershipId,
                );
            }
        }
    }

    /**
     * A secret header keeps only its name and the flag.
     *
     * @param  list<array{name: string, value: string, secret?: true}>  $headers
     * @return list<array{name: string, value?: string, secret?: true}>
     */
    private function storedHeaders(array $headers): array
    {
        return array_map(fn (array $h): array => isset($h['secret']) ? ['name' => $h['name'], 'secret' => true] : $h, $headers);
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

        return $row === null ? null : $this->source($row, $this->vault->status($workspaceId, strtolower($row->id)));
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
            'api_key_name' => $source->apiKeyName,
            'api_key_placement' => $source->apiKeyPlacement,
            'oauth_token_url' => $source->oauthTokenUrl,
            'oauth_client_id' => $source->oauthClientId,
            'oauth_scope' => $source->oauthScope,
            'timeout_seconds' => $source->timeoutSeconds,
            'max_response_bytes' => $source->maxResponseBytes,
            'max_pages' => $source->maxPages,
            'live_capable' => $source->liveCapable ? 'true' : 'false',
            'pagination_style' => $source->pagination->style,
            'pagination_param' => $source->pagination->param,
            'pagination_size_param' => $source->pagination->sizeParam,
            'pagination_size' => $source->pagination->size,
            'pagination_records_path' => $source->pagination->recordsPath,
            'pagination_cursor_path' => $source->pagination->cursorPath,
            'header_count' => count($headers),
            'headers' => json_encode(array_map(fn (array $h): array => isset($h['secret']) ? [strtolower($h['name']), '', true] : [strtolower($h['name']), $h['value']], $headers), JSON_THROW_ON_ERROR),
            'revision' => $source->revision,
        ];
    }

    /** @return list<int|string|null> the six pagination columns, in the order the statements list them */
    private function paginationValues(Pagination $pagination): array
    {
        return [$pagination->style, $pagination->param, $pagination->sizeParam, $pagination->size, $pagination->recordsPath, $pagination->cursorPath];
    }

    /** @param  array<string, SecretStatus>  $secrets */
    private function source(object $row, array $secrets = []): DataSource
    {
        /** @var object{id: string, name: string, base_url: string, scheme: string, host: string, port: int|string, auth_type: string, default_headers: string, timeout_seconds: int|string|null, max_response_bytes: int|string|null, max_pages: int|string|null, live_capable: bool|string|int, api_key_name: string|null, api_key_placement: string|null, oauth_token_url: string|null, oauth_client_id: string|null, oauth_scope: string|null, pagination_style: string, pagination_param: string|null, pagination_size_param: string|null, pagination_size: int|string|null, pagination_records_path: string|null, pagination_cursor_path: string|null, revision: int|string, lock_epoch: int|string, created: string, updated: string} $row */
        $decoded = json_decode($row->default_headers, true);
        $headers = [];

        foreach (is_array($decoded) ? $decoded : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && ($header['secret'] ?? false) === true) {
                $headers[] = ['name' => $header['name'], 'value' => '', 'secret' => true];
            } elseif (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null)) {
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
            $row->api_key_name,
            $row->api_key_placement,
            $secrets,
            $row->oauth_token_url,
            $row->oauth_client_id,
            $row->oauth_scope,
            (int) $row->lock_epoch,
            new Pagination($row->pagination_style, $row->pagination_param, $row->pagination_size_param, $row->pagination_size === null ? null : (int) $row->pagination_size, $row->pagination_records_path, $row->pagination_cursor_path),
        );
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
