<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\Permission;
use Illuminate\Support\Facades\DB;

/**
 * The row locks and reads shared by the commands that change a membership (Stories 1.22 and 1.24): one locking
 * statement takes the target and every active Admin holding `users.manage` in id order (so two concurrent changes
 * cannot deadlock), and the last-holder recount runs after those locks, as its own statement.
 */
final class MembershipLocks
{
    /**
     * Locks the target and every active Admin holding `users.manage`, in id order.
     *
     * @return list<string> the locked membership IDs (lower case)
     */
    public function lock(string $workspaceId, string $membershipId): array
    {
        $rows = DB::select(
            <<<'SQL'
                select m.id
                from workspace_memberships m
                where m.workspace_id = ?
                  and (m.id = ?
                       or (m.status = 'active' and m.role = 'admin'
                           and exists (select 1 from membership_permissions p where p.membership_id = m.id and p.permission = ?)))
                order by m.id
                for update of m
                SQL,
            [$workspaceId, $membershipId, Permission::UsersManage->value],
        );

        /** @var list<object{id: string}> $rows */
        return array_map(fn (object $row): string => strtolower((string) $row->id), $rows);
    }

    /**
     * Whether a membership with this role, status and permission set counts as an active holder of `users.manage`: an
     * active Admin membership that holds the permission. The one definition behind the lock query, the editor's own
     * authority and the last-holder rule.
     *
     * @param  list<string>  $permissions
     */
    public function isActiveHolder(string $role, string $status, array $permissions): bool
    {
        return $status === 'active' && $role === 'admin' && in_array(Permission::UsersManage->value, $permissions, true);
    }

    public function read(string $membershipId): ?AccessSnapshot
    {
        /** @var object{role: string, status: string, revision: int|string}|null $row */
        $row = DB::selectOne('select role, status, revision from workspace_memberships where id = ?', [$membershipId]);

        if ($row === null) {
            return null;
        }

        /** @var list<string> $held */
        $held = DB::table('membership_permissions')->where('membership_id', $membershipId)->pluck('permission')->all();

        $held = array_values(array_unique(array_intersect($held, Permission::values())));
        sort($held);

        return new AccessSnapshot($row->role, $row->status, (int) $row->revision, $held);
    }

    /**
     * The other active Admin holders of `users.manage`, counted now that their rows are locked: a statement of its own
     * sees what a concurrent change committed while this one waited for the lock.
     *
     * @return list<object>
     */
    public function otherHolders(string $workspaceId, string $membershipId): array
    {
        return array_values(DB::select(
            <<<'SQL'
                select m.id
                from workspace_memberships m
                where m.workspace_id = ? and m.id <> ? and m.status = 'active' and m.role = 'admin'
                  and exists (select 1 from membership_permissions p where p.membership_id = m.id and p.permission = ?)
                SQL,
            [$workspaceId, $membershipId, Permission::UsersManage->value],
        ));
    }
}
