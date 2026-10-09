<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Ingestion\Contracts\RefreshIntervals;
use App\Modules\Ingestion\Contracts\SourceHealth;
use App\Modules\Ingestion\Contracts\SourceHealths;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Create block wizard's Step 2 reads (Story 3.7), for an Admin with `blocks.edit` who need not hold `data_sources.manage`: the Data
 * Sources with their health and Live flag, the Endpoints of one with their parameters, and the System-settings refresh intervals. Read-only
 * and Workspace-scoped (another Workspace's rows are invisible). It never carries a header, a credential, a body template or a test value:
 * only what picking and binding an Endpoint needs. Nothing here contacts any host, and nothing is cached.
 */
final class BlockSourceController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DataSources $sources,
        private readonly Endpoints $endpoints,
        private readonly SourceHealths $healths,
        private readonly RefreshIntervals $intervals,
    ) {}

    public function dataSources(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $rows = $this->sources->list($workspaceId, new DataSourceQuery)->rows;
        $healths = $this->healths->forDataSources($workspaceId, array_map(fn (DataSource $row): string => $row->id, $rows));

        return response()->json(['data' => array_map(fn (DataSource $row): array => [
            'data_source_id' => $row->id,
            'name' => $row->name,
            'health' => ($healths[$row->id] ?? new SourceHealth)->status,
            'live_capable' => $row->liveCapable,
            'revision' => $row->revision,
        ], $rows)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function endpoints(Request $request, string $dataSource): JsonResponse
    {
        try {
            $page = $this->endpoints->list($this->workspaceId($request), $dataSource, null);
        } catch (DataSourceNotFound) {
            abort(404);
        }

        return response()->json(['data' => array_map(fn (Endpoint $row): array => [
            'endpoint_id' => $row->id,
            'data_source_id' => $row->dataSourceId,
            'method' => $row->method,
            'path' => $row->pathTemplate,
            'params' => array_map(fn (mixed $entry): array => [
                'name' => self::field($entry, 'name', ''),
                'binding' => self::field($entry, 'binding', 'fixed'),
                // A user-bound row holds a kind and at most an attribute key id: never a user's value.
                'value' => self::field($entry, 'value', null),
                'kind' => self::field($entry, 'kind', 'query'),
            ], $row->params),
            'read_only_query' => $row->readOnlyQuery,
            'requires_user_context' => $row->requiresUserContext,
            'revision' => $row->revision,
            'revision_id' => $row->revisionId,
        ], $page->rows)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** The intervals in whole seconds, ascending; `intervals` is null when the setting is unset or malformed (none is offered, none invented). */
    public function refreshIntervals(): JsonResponse
    {
        return response()->json(['data' => ['intervals' => $this->intervals->refreshIntervals()]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** A key of a stored parameter entry, or its default: an entry missing keys must not fail the whole list. */
    private static function field(mixed $entry, string $key, ?string $default): ?string
    {
        return is_array($entry) && isset($entry[$key]) && is_string($entry[$key]) ? $entry[$key] : $default;
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
