<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\GroupDirectory;
use App\Modules\Access\Contracts\GroupManager;
use App\Modules\Access\Contracts\GroupNameTaken;
use App\Modules\Access\Contracts\GroupNotFound;
use App\Modules\Access\Contracts\GroupRow;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, renames and deletes groups and changes their members (Story 1.23) inside the Workspace's transaction.
 *
 * Every change locks the group's row first (so a delete cannot race an add), refuses a group or a member that row-level
 * security does not show (404, never a hint that it exists elsewhere), and writes `access.group.changed` to the audit
 * log and the outbox in the same transaction. The audit carries the group and member IDs, the kind of change, counts and
 * the group name as a keyed hash; the outbox event carries IDs, the kind of change and a count, never a name. Removals
 * go through SECURITY DEFINER functions (role `app` has no DELETE on tenant tables).
 */
final class ManageGroups implements GroupManager
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly GroupDirectory $directory,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function create(MemberEditor $editor, string $name): GroupRow
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $name): GroupRow {
            $this->assertNameFree($editor->workspaceId, $name, null);

            $id = (string) Str::uuid7();

            try {
                DB::insert(
                    'insert into user_groups (id, workspace_id, name, created_at, updated_at) values (?, ?, ?, ?, ?)',
                    [$id, $editor->workspaceId, $name, now(), now()],
                );
            } catch (QueryException $e) {
                throw $this->nameTaken($e);
            }

            $this->record($editor, $id, 'created', after: ['name' => $name, 'member_count' => 0]);

            return $this->row($editor, $id);
        });
    }

    public function rename(MemberEditor $editor, string $groupId, string $name): GroupRow
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $groupId, $name): GroupRow {
            $groupId = $this->lock($editor, $groupId);
            $before = $this->name($groupId);

            if ($before === $name) {
                return $this->row($editor, $groupId);
            }

            $this->assertNameFree($editor->workspaceId, $name, $groupId);

            try {
                DB::update('update user_groups set name = ?, updated_at = ? where id = ? and workspace_id = ?', [$name, now(), $groupId, $editor->workspaceId]);
            } catch (QueryException $e) {
                throw $this->nameTaken($e);
            }

            $this->record($editor, $groupId, 'renamed', after: ['name' => $name, 'member_count' => $this->count($groupId)], before: ['name' => $before]);

            return $this->row($editor, $groupId);
        });
    }

    public function delete(MemberEditor $editor, string $groupId): int
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $groupId): int {
            $groupId = $this->lock($editor, $groupId);
            $name = $this->name($groupId);
            $count = $this->count($groupId);

            // Epic 6 hook: access_delete_group is where a group that an access grant references is refused or reported.
            /** @var object{removed: int|string|null} $row */
            $row = DB::selectOne('select access_delete_group(?::uuid) as removed', [$groupId]);

            if ($row->removed === null) {
                throw new GroupNotFound;
            }

            $this->record($editor, $groupId, 'deleted', after: ['member_count' => 0], before: ['name' => $name, 'member_count' => $count]);

            return (int) $row->removed;
        });
    }

    public function addMember(MemberEditor $editor, string $groupId, string $membershipId): GroupRow
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $groupId, $membershipId): GroupRow {
            $groupId = $this->lock($editor, $groupId);
            $membershipId = $this->member($editor, $membershipId);

            $added = DB::affectingStatement(
                'insert into group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?) on conflict (group_id, membership_id) do nothing',
                [(string) Str::uuid7(), $editor->workspaceId, $groupId, $membershipId, now(), now()],
            );

            // Adding a member who is already in the group changes nothing and records nothing.
            if ($added === 1) {
                $count = $this->count($groupId);
                $this->record($editor, $groupId, 'member_added', after: ['member_count' => $count], before: ['member_count' => $count - 1], membershipId: $membershipId);
            }

            return $this->row($editor, $groupId);
        });
    }

    public function removeMember(MemberEditor $editor, string $groupId, string $membershipId): GroupRow
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $groupId, $membershipId): GroupRow {
            $groupId = $this->lock($editor, $groupId);

            // A membership that is not in this Workspace is simply not in the group: a no-op, not a 404.
            if (! Str::isUuid($membershipId)) {
                throw new MembershipNotFound;
            }

            $membershipId = strtolower($membershipId);

            /** @var object{removed: bool|string|int} $row */
            $row = DB::selectOne('select access_remove_group_member(?::uuid, ?::uuid) as removed', [$groupId, $membershipId]);

            if (filter_var($row->removed, FILTER_VALIDATE_BOOLEAN)) {
                $count = $this->count($groupId);
                $this->record($editor, $groupId, 'member_removed', after: ['member_count' => $count], before: ['member_count' => $count + 1], membershipId: $membershipId);
            }

            return $this->row($editor, $groupId);
        });
    }

    /** The group as it stands in this transaction (so a concurrent delete cannot turn a done change into a 404). */
    private function row(MemberEditor $editor, string $groupId): GroupRow
    {
        return $this->directory->find($editor->workspaceId, $groupId) ?? throw new GroupNotFound;
    }

    /** Locks the group's row and returns its ID (lower case); a group row-level security does not show is not found. */
    private function lock(MemberEditor $editor, string $groupId): string
    {
        if (! Str::isUuid($groupId)) {
            throw new GroupNotFound;
        }

        $groupId = strtolower($groupId);
        $row = DB::selectOne('select id from user_groups where id = ? and workspace_id = ? for update', [$groupId, $editor->workspaceId]);

        return $row === null ? throw new GroupNotFound : $groupId;
    }

    /** The membership's ID when it is a member of this Workspace (row-level security shows no other). */
    private function member(MemberEditor $editor, string $membershipId): string
    {
        if (! Str::isUuid($membershipId)) {
            throw new MembershipNotFound;
        }

        $membershipId = strtolower($membershipId);
        $row = DB::selectOne('select id from workspace_memberships where id = ? and workspace_id = ?', [$membershipId, $editor->workspaceId]);

        return $row === null ? throw new MembershipNotFound : $membershipId;
    }

    private function name(string $groupId): string
    {
        /** @var object{name: string} $row */
        $row = DB::selectOne('select name from user_groups where id = ?', [$groupId]);

        return $row->name;
    }

    private function count(string $groupId): int
    {
        /** @var object{n: int|string} $row */
        $row = DB::selectOne('select count(*) as n from group_members where group_id = ?', [$groupId]);

        return (int) $row->n;
    }

    private function assertNameFree(string $workspaceId, string $name, ?string $exceptGroupId): void
    {
        $taken = DB::selectOne(
            'select 1 as taken from user_groups where workspace_id = ? and lower(btrim(name)) = lower(btrim(?)) and (?::uuid is null or id <> ?::uuid) limit 1',
            [$workspaceId, $name, $exceptGroupId, $exceptGroupId],
        );

        if ($taken !== null) {
            throw new GroupNameTaken;
        }
    }

    private function nameTaken(QueryException $e): QueryException|GroupNameTaken
    {
        // A concurrent create or rename won the race for the name: the unique index refuses the second.
        return ($e->errorInfo[0] ?? null) === '23505' ? new GroupNameTaken : $e;
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    private function record(MemberEditor $editor, string $groupId, string $change, array $after, array $before = [], ?string $membershipId = null): void
    {
        $subject = 'group:'.$groupId;
        $ids = ['group_id' => $groupId, 'change' => $change] + ($membershipId === null ? [] : ['membership_id' => $membershipId]);

        $this->audit->record(AuditAction::AccessGroupChanged, $ids + $after, $before === [] ? [] : ['group_id' => $groupId] + $before, subject: $subject, actor: $editor->membershipId);
        $this->outbox->emit(AuditAction::AccessGroupChanged, $subject, $ids, actor: $editor->membershipId);
    }
}
