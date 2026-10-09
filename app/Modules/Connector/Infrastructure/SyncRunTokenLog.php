<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Application\RecordSyncRun;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Support\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records each token request as a `sync_runs` row of kind `oauth_token` (Story 2.7), in the caller's Workspace transaction
 * and in its own savepoint: a failing history write is logged (identifiers only) and never changes the outcome of the call.
 */
final class SyncRunTokenLog implements TokenRequestLog
{
    public function __construct(private readonly RecordSyncRun $runs, private readonly RequestContext $context) {}

    public function record(string $workspaceId, ?string $dataSourceId, string $tokenUrl, ?int $httpStatus, int $latencyMs, int $bytes, ?string $code, \DateTimeInterface $startedAt): void
    {
        try {
            DB::transaction(fn () => $this->runs->record(
                $workspaceId, $dataSourceId, self::KIND, $tokenUrl, $code === null,
                $httpStatus, $latencyMs, $bytes, $code, $this->context->requestId(), $startedAt,
            ));
        } catch (Throwable $e) {
            Log::error('connector.oauth.sync_run_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }
    }
}
