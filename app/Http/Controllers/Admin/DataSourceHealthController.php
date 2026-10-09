<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Ingestion\Contracts\SourceHealth;
use App\Modules\Ingestion\Contracts\SourceHealths;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET api/v1/admin/data-source-health` (Story 2.18): the name, id, health and last success of each Data Source, for the Admin overview's "Your
 * data sources". It sits behind the `admin` middleware with `data_sources.manage` (a 403 and its audit come from there, mapped in
 * `ShellNavigation::ADMIN_API_ROUTES`). Read only: it contacts nothing and is never cached.
 */
final class DataSourceHealthController extends Controller
{
    public function __construct(
        private readonly DataSources $sources,
        private readonly SourceHealths $healths,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);
        abort_unless(is_string($workspaceId), 404);

        $rows = $this->sources->list($workspaceId, new DataSourceQuery)->rows;
        $healths = $this->healths->forDataSources($workspaceId, array_map(fn (DataSource $row): string => $row->id, $rows));

        return response()->json([
            'data' => array_map(fn (DataSource $row): array => [
                'data_source_id' => $row->id,
                'name' => $row->name,
                'health' => ($healths[$row->id] ?? new SourceHealth)->status,
                'last_successful_call_at' => ($healths[$row->id] ?? new SourceHealth)->lastSuccessAt,
            ], $rows),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
