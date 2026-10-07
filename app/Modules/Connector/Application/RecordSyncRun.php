<?php

namespace App\Modules\Connector\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one `sync_runs` row for an outbound attempt, in the caller's Workspace transaction (Story 2.5). The row holds the
 * kind, the sanitised URL template (no query, userinfo or fragment: cut again here whatever the caller passes), the outcome
 * and its numbers. Never a body, a header or a secret.
 */
final class RecordSyncRun
{
    public function record(
        string $workspaceId,
        ?string $dataSourceId,
        string $kind,
        string $urlTemplate,
        bool $succeeded,
        ?int $httpStatus,
        ?int $latencyMs,
        ?int $bytes,
        ?string $errorCode,
        ?string $requestId,
        \DateTimeInterface $startedAt,
    ): string {
        $id = (string) Str::uuid7();
        $url = (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', (string) preg_replace('/[?#].*\z/s', '', $urlTemplate));

        DB::insert(
            'insert into sync_runs (id, workspace_id, data_source_id, kind, url_template, status, http_status, latency_ms, bytes, error_code, request_id, started_at, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
            [
                $id, $workspaceId, $dataSourceId, $kind, substr($url, 0, 2048), $succeeded ? 'succeeded' : 'failed', $httpStatus,
                $latencyMs, $bytes, $errorCode === null ? null : substr($errorCode, 0, 48), $requestId === null ? null : substr($requestId, 0, 64), $startedAt->format('Y-m-d H:i:s.uP'),
            ],
        );

        return $id;
    }
}
