<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\SyncRunLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one `sync_runs` row for an outbound attempt, in the caller's Workspace transaction (Story 2.5). The row holds the
 * kind, the sanitised URL template (no query, userinfo or fragment: cut again here whatever the caller passes), the outcome
 * and its numbers. Never a body, a header or a secret.
 */
final class RecordSyncRun implements SyncRunLog
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
        ?string $outcome = null,
    ): string {
        $id = (string) Str::uuid7();
        $url = (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', (string) preg_replace('/[?#].*\z/s', '', $urlTemplate));

        DB::insert(
            'insert into sync_runs (id, workspace_id, data_source_id, kind, url_template, status, http_status, latency_ms, bytes, error_code, request_id, started_at, outcome, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
            [
                $id, $workspaceId, $dataSourceId, $kind, substr($url, 0, 2048), $succeeded ? 'succeeded' : 'failed', $httpStatus,
                $latencyMs, $bytes, $errorCode === null ? null : substr($errorCode, 0, 48), $requestId === null ? null : substr($requestId, 0, 64), $startedAt->format('Y-m-d H:i:s.uP'), $outcome,
            ],
        );

        return $id;
    }

    public function recordScheduledFetch(
        string $workspaceId,
        ?string $id,
        ?string $dataSourceId,
        string $syncTargetId,
        int $dispatchSeq,
        string $urlTemplate,
        array $parameterNames,
        string $status,
        ?int $httpStatus,
        ?int $latencyMs,
        ?int $bytes,
        ?string $errorCode,
        ?string $requestId,
        \DateTimeInterface $startedAt,
        ?string $outcome = null,
        ?int $attempt = null,
    ): string {
        $id ??= (string) Str::uuid7();
        $url = (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', (string) preg_replace('/[?#].*\z/s', '', $urlTemplate));
        // Names only, and only names a parameter may have: a value can never be mistaken for one.
        $names = array_values(array_filter($parameterNames, fn (string $name): bool => preg_match('/\A(?:header:[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}|[A-Za-z0-9_.~\-]{1,64})\z/D', $name) === 1));

        DB::insert(
            'insert into sync_runs (id, workspace_id, data_source_id, kind, url_template, status, http_status, latency_ms, bytes, error_code, request_id, started_at, sync_target_id, dispatch_seq, parameter_names, outcome, attempt, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, now(), now())',
            [
                $id, $workspaceId, $dataSourceId, self::KIND, substr($url, 0, 2048), $status, $httpStatus, $latencyMs, $bytes,
                $errorCode === null ? null : substr($errorCode, 0, 48), $requestId === null ? null : substr($requestId, 0, 64),
                $startedAt->format('Y-m-d H:i:s.uP'), $syncTargetId, $dispatchSeq, json_encode($names, JSON_THROW_ON_ERROR), $outcome, $attempt,
            ],
        );

        return $id;
    }
}
