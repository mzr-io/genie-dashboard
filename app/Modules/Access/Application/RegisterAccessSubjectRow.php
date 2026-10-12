<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\RegisterAccessSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Writes the `access_subjects` row of a subject (Story 3.19) with the default access "All users", in the caller's Workspace transaction. The unique
 * index on (Workspace, type, subject) makes it idempotent, also under a race.
 */
final class RegisterAccessSubjectRow implements RegisterAccessSubject
{
    public function register(string $workspaceId, string $type, string $subjectId): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An Access subject is registered inside a Workspace transaction.');
        }

        if (! in_array($type, [self::BLOCK, self::TEMPLATE], true) || ! Str::isUuid($subjectId)) {
            throw new InvalidArgumentException('An Access subject is a block or a template with a UUID.');
        }

        return DB::affectingStatement(
            "insert into access_subjects (id, workspace_id, subject_type, subject_id, visible, mode, created_at, updated_at) values (?, ?, ?, ?, true, 'all', ?, ?) on conflict (workspace_id, subject_type, subject_id) do nothing",
            [(string) Str::uuid7(), $workspaceId, $type, strtolower($subjectId), now(), now()],
        ) === 1;
    }
}
