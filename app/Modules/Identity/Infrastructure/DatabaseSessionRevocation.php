<?php

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Contracts\SessionRevocation;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Revokes sessions of the `database` session driver. The `sessions` table has no Workspace column (the active
 * Workspace lives in the stored payload), so each of the user's rows is decoded: the payload is base64 of either PHP
 * serialization (decoded with `allowed_classes => false`, so no class is ever instantiated) or JSON, whichever the
 * session `serialization` setting wrote, decrypted first when `session.encrypt` is on. Other drivers are skipped with a
 * logged notice, and the count of undecodable rows is logged (reason and count only). A row that cannot be decoded is left alone.
 */
final class DatabaseSessionRevocation implements SessionRevocation
{
    /** @param  string|null  $driver  the session driver (the `session.driver` setting when null) */
    public function __construct(private readonly ?string $driver = null) {}

    public function revokeForWorkspace(int $userId, string $workspaceId): int
    {
        $workspaceId = strtolower($workspaceId);

        if (($this->driver ?? Config::get('session.driver')) !== 'database') {
            // Other drivers keep sessions elsewhere; the per-request membership check still ends them.
            Log::notice('identity.session.revocation_skipped', ['reason' => 'driver_not_database']);

            return 0;
        }

        /** @var list<object{id: string, payload: string}> $rows */
        $rows = DB::select('select id, payload from sessions where user_id = ?', [$userId]);
        $ids = [];
        $undecodable = 0;

        foreach ($rows as $row) {
            $decoded = self::decode((string) $row->payload);

            if ($decoded === null) {
                $undecodable++;

                continue;
            }

            $stored = $decoded[WorkspaceTransaction::SESSION_KEY] ?? null;

            if (is_string($stored) && strtolower($stored) === $workspaceId) {
                $ids[] = (string) $row->id;
            }
        }

        if ($undecodable > 0) {
            Log::notice('identity.session.revocation_undecodable', ['reason' => 'payload_not_decodable', 'count' => $undecodable]);
        }

        if ($ids === []) {
            return 0;
        }

        return DB::table('sessions')->where('user_id', $userId)->whereIn('id', $ids)->delete();
    }

    /** @return array<mixed>|null null when the payload cannot be decoded safely */
    private static function decode(string $payload): ?array
    {
        try {
            $raw = base64_decode($payload, true);

            if ($raw === false || $raw === '') {
                return null;
            }

            if (Config::get('session.encrypt')) {
                $raw = Crypt::decrypt($raw);

                if (! is_string($raw)) {
                    return null;
                }
            }

            $data = str_starts_with($raw, '{')
                ? json_decode($raw, true)
                : @unserialize($raw, ['allowed_classes' => false]);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
