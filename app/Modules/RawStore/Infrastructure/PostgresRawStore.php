<?php

namespace App\Modules\RawStore\Infrastructure;

use App\Modules\RawStore\Contracts\RawPayload;
use App\Modules\RawStore\Contracts\RawStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * Stores bodies as `bytea` (lz4 in TOAST), never as `jsonb`: the bytes are sent hex-encoded, so no character set, escape or
 * number is ever interpreted on the way in or out. `content_hash` is the sha256 of the exact bytes (the table checks it).
 * Observations go into the monthly partition of `observed_at`. Both tables are insert-only for the application role.
 */
final class PostgresRawStore implements RawStore
{
    public function put(string $workspaceId, string $syncTargetId, int $seq, int $dispatchSeq, #[\SensitiveParameter] string $body, ?string $requestId, \DateTimeInterface $observedAt): RawPayload
    {
        if (! Str::isUuid($syncTargetId)) {
            throw new LogicException('A raw payload belongs to a sync target.');
        }

        $workspaceId = strtolower($workspaceId);
        $syncTargetId = strtolower($syncTargetId);
        $hash = hash('sha256', $body);
        $size = strlen($body);

        try {
            return $this->store($workspaceId, $syncTargetId, $seq, $dispatchSeq, $body, $requestId, $observedAt, $hash, $size);
        } catch (QueryException $e) {
            // A database error message carries the bound values, which here are the body: only the SQLSTATE leaves.
            throw new RuntimeException('The raw payload could not be stored (SQLSTATE '.($e->errorInfo[0] ?? 'unknown').').');
        }
    }

    private function store(string $workspaceId, string $syncTargetId, int $seq, int $dispatchSeq, #[\SensitiveParameter] string $body, ?string $requestId, \DateTimeInterface $observedAt, string $hash, int $size): RawPayload
    {
        // The same bytes of the same target are one row: a later identical response only adds an observation.
        DB::insert(
            "insert into raw_bodies (id, workspace_id, sync_target_id, content_hash, size_bytes, body, created_at) values (?, ?, ?, ?, ?, decode(?, 'hex'), now()) on conflict (workspace_id, sync_target_id, content_hash) do nothing",
            [(string) Str::uuid7(), $workspaceId, $syncTargetId, $hash, $size, bin2hex($body)],
        );

        $row = DB::selectOne(
            'select id from raw_bodies where workspace_id = ? and sync_target_id = ? and content_hash = ?',
            [$workspaceId, $syncTargetId, $hash],
        );

        if ($row === null) {
            throw new LogicException('The raw body was not stored.');
        }

        $payloadId = strtolower((string) $row->id);

        DB::insert(
            'insert into raw_observations (id, workspace_id, sync_target_id, payload_id, seq, content_hash, size_bytes, dispatch_seq, request_id, observed_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) Str::uuid7(), $workspaceId, $syncTargetId, $payloadId, $seq, $hash, $size, $dispatchSeq,
                $requestId === null ? null : substr($requestId, 0, 64), $observedAt->format('Y-m-d H:i:s.uP'),
            ],
        );

        return new RawPayload($payloadId, $hash, $size);
    }

    public function get(string $workspaceId, string $syncTargetId, string $payloadId): ?string
    {
        if (! Str::isUuid($syncTargetId) || ! Str::isUuid($payloadId)) {
            return null;
        }

        $row = DB::selectOne(
            "select encode(body, 'hex') as hex from raw_bodies where workspace_id = ? and sync_target_id = ? and id = ?",
            [strtolower($workspaceId), strtolower($syncTargetId), strtolower($payloadId)],
        );

        if ($row === null) {
            return null;
        }

        $bytes = hex2bin((string) $row->hex);

        return $bytes === false ? null : $bytes;
    }
}
