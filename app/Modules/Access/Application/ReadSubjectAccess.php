<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\SubjectAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see SubjectAccess} over `access_subjects`. The membership is accepted now so group modes can use it without a contract change. */
final class ReadSubjectAccess implements SubjectAccess
{
    public function canView(string $workspaceId, string $membershipId, string $subjectType, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(strtolower(...), $subjectIds), Str::isUuid(...))));
        $out = array_fill_keys($ids, false);

        if ($ids === []) {
            return array_fill_keys(array_map(strtolower(...), $subjectIds), false);
        }

        $rows = DB::select(
            'select subject_id, visible, mode from access_subjects where workspace_id = ? and subject_type = ? and subject_id in ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [$workspaceId, $subjectType, ...$ids],
        );

        foreach ($rows as $row) {
            $out[strtolower((string) $row->subject_id)] = (bool) $row->visible && (string) $row->mode === 'all';
        }

        return $out + array_fill_keys(array_map(strtolower(...), $subjectIds), false);
    }
}
