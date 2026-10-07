<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\AccessChange;
use App\Modules\Access\Contracts\EditorNotAuthorized;
use App\Modules\Access\Contracts\LastUsersManageHolder;
use App\Modules\Access\Contracts\MemberAccess;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipInactive;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\PasswordNotConfirmed;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Access\Contracts\PermissionNotHeld;
use App\Modules\Access\Contracts\PermissionsOnUserRole;
use App\Modules\Access\Contracts\RevisionConflict;
use App\Modules\Access\Contracts\SelfChangeForbidden;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changes a member's role and permissions (Story 1.22) inside the Workspace's transaction.
 *
 * One locking statement takes the target membership and every active Admin holding `users.manage` in id order (so two
 * concurrent edits cannot deadlock); the target is re-read, and the holders counted again, after the locks, so the
 * revision, the last-holder rule and the before-state are the committed truth. The order of refusals is: self edit, the editor's own authority (an active Admin holding `users.manage` among the locked rows), not found, an inactive member, stale revision, permissions
 * on a User, the granter cap (both directions, read now), the last `users.manage` holder, and last the password (only
 * when the permission set changes or the role becomes `admin`). Nothing is cached: the next request reads the rows.
 *
 * Audit (`access.role.changed`, `access.permission.changed`) and the outbox events of the same types are written in
 * the same transaction. Both carry IDs, enums and counts only; the audit also lists the permission names.
 */
final class ChangeMemberAccess implements MemberAccess
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly MembershipPermissions $held,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function update(MemberEditor $editor, string $membershipId, int $revision, ?string $role, ?array $permissions, Closure $confirmPassword): AccessChange
    {
        if ($role !== null && ! in_array($role, ['user', 'admin'], true)) {
            throw new InvalidArgumentException('A role is user or admin.');
        }

        if ($permissions !== null && array_diff($permissions, Permission::values()) !== []) {
            throw new InvalidArgumentException('Permissions come from the closed catalogue.');
        }

        if (! Str::isUuid($membershipId)) {
            throw new MembershipNotFound;
        }

        $membershipId = strtolower($membershipId);

        if ($membershipId === strtolower($editor->membershipId)) {
            throw new SelfChangeForbidden;
        }

        $permissions = $permissions === null ? null : self::sorted($permissions);

        return $this->transactions->run($editor->workspaceId, function () use ($editor, $membershipId, $revision, $role, $permissions, $confirmPassword): AccessChange {
            $locked = $this->lock($editor->workspaceId, $membershipId);

            // The editor's own authority, seen under the lock: an active Admin holding users.manage is among the locked rows.
            if (! in_array(strtolower($editor->membershipId), $locked, true)) {
                throw new EditorNotAuthorized;
            }

            if (! in_array($membershipId, $locked, true)) {
                throw new MembershipNotFound;
            }

            $target = $this->read($membershipId);

            if ($target === null) {
                throw new MembershipNotFound;
            }

            if ($target->status !== 'active') {
                throw new MembershipInactive;
            }

            if ($target->revision !== $revision) {
                throw new RevisionConflict(new AccessChange($membershipId, $target->role, $target->permissions, $target->revision, false));
            }

            $newRole = $role ?? $target->role;

            if ($newRole === 'user') {
                if ($permissions !== null && $permissions !== []) {
                    throw new PermissionsOnUserRole;
                }

                $newPermissions = [];
            } elseif ($permissions !== null) {
                $newPermissions = $permissions;
            } else {
                // Becoming an Admin starts from what was given (nothing); an Admin who stays one keeps the set.
                $newPermissions = $target->role === 'admin' ? $target->permissions : [];
            }

            $roleChanged = $newRole !== $target->role;
            $setChanged = $newPermissions !== $target->permissions;

            // The password is asked for when the permission set changes or the role becomes Admin.
            $needsPassword = $setChanged || ($roleChanged && $newRole === 'admin');

            if (! $roleChanged && ! $setChanged) {
                return new AccessChange($membershipId, $target->role, $target->permissions, $target->revision, false);
            }

            $this->assertHeld($editor, $target->permissions, $newPermissions);
            $this->assertNotLastHolder($editor->workspaceId, $membershipId, $target, $newRole, $newPermissions);

            if ($needsPassword && ! $confirmPassword()) {
                throw new PasswordNotConfirmed;
            }

            $updated = DB::update(
                'update workspace_memberships set role = ?, revision = revision + 1, updated_at = ? where id = ? and workspace_id = ?',
                [$newRole, now(), $membershipId, $editor->workspaceId],
            );

            // Exactly one row, or nothing is written (the transaction rolls back before any audit or outbox row).
            if ($updated !== 1) {
                throw new MembershipNotFound;
            }

            if ($setChanged) {
                DB::selectOne('select access_set_membership_permissions(?, ?::jsonb)', [$membershipId, json_encode($newPermissions, JSON_THROW_ON_ERROR)]);
            }

            $this->record($editor, $membershipId, $target, $newRole, $newPermissions, $roleChanged, $setChanged);

            return new AccessChange($membershipId, $newRole, $newPermissions, $target->revision + 1);
        });
    }

    /**
     * Locks the target and every active Admin holding `users.manage`, in id order.
     *
     * @return list<string> the locked membership IDs (lower case)
     */
    private function lock(string $workspaceId, string $membershipId): array
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

    private function read(string $membershipId): ?AccessSnapshot
    {
        /** @var object{role: string, status: string, revision: int|string}|null $row */
        $row = DB::selectOne('select role, status, revision from workspace_memberships where id = ?', [$membershipId]);

        if ($row === null) {
            return null;
        }

        /** @var list<string> $held */
        $held = DB::table('membership_permissions')->where('membership_id', $membershipId)->pluck('permission')->all();

        return new AccessSnapshot($row->role, $row->status, (int) $row->revision, self::sorted(array_values(array_intersect($held, Permission::values()))));
    }

    /**
     * The granter cap in both directions: every permission added or removed must be one the editor holds now.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    private function assertHeld(MemberEditor $editor, array $old, array $new): void
    {
        $changed = array_values(array_unique([...array_diff($new, $old), ...array_diff($old, $new)]));

        if ($changed === []) {
            return;
        }

        $held = array_map(fn (Permission $permission): string => $permission->value, $this->held->forUser($editor->userId, $editor->workspaceId));
        $missing = array_values(array_diff($changed, $held));

        if ($missing !== []) {
            throw new PermissionNotHeld(self::sorted($missing));
        }
    }

    /**
     * The holders are counted again now that their rows are locked: a statement of its own sees what a concurrent
     * edit committed while this one waited for the lock, so two Admins cannot each remove the other's permission.
     *
     * @param  list<string>  $newPermissions
     */
    private function assertNotLastHolder(string $workspaceId, string $membershipId, AccessSnapshot $target, string $newRole, array $newPermissions): void
    {
        $wasHolder = $target->status === 'active' && $target->role === 'admin' && in_array(Permission::UsersManage->value, $target->permissions, true);
        $stillHolder = $target->status === 'active' && $newRole === 'admin' && in_array(Permission::UsersManage->value, $newPermissions, true);

        if (! $wasHolder || $stillHolder) {
            return;
        }

        $others = DB::select(
            <<<'SQL'
                select m.id
                from workspace_memberships m
                where m.workspace_id = ? and m.id <> ? and m.status = 'active' and m.role = 'admin'
                  and exists (select 1 from membership_permissions p where p.membership_id = m.id and p.permission = ?)
                SQL,
            [$workspaceId, $membershipId, Permission::UsersManage->value],
        );

        if ($others === []) {
            throw new LastUsersManageHolder;
        }
    }

    /**
     * @param  list<string>  $newPermissions
     */
    private function record(MemberEditor $editor, string $membershipId, AccessSnapshot $target, string $newRole, array $newPermissions, bool $roleChanged, bool $setChanged): void
    {
        $subject = 'membership:'.$membershipId;
        $count = count($newPermissions);
        $before = count($target->permissions);

        if ($roleChanged) {
            $this->audit->record(
                AuditAction::AccessRoleChanged,
                ['membership_id' => $membershipId, 'role' => $newRole, 'permission_count' => $count],
                ['membership_id' => $membershipId, 'role' => $target->role, 'permission_count' => $before],
                subject: $subject,
                actor: $editor->membershipId,
            );
            $this->outbox->emit(AuditAction::AccessRoleChanged, $subject, [
                'membership_id' => $membershipId,
                'role' => $newRole,
                'previous_role' => $target->role,
            ], actor: $editor->membershipId);
        }

        if ($setChanged) {
            $this->audit->record(
                AuditAction::AccessPermissionChanged,
                ['membership_id' => $membershipId, 'role' => $newRole, 'permission_count' => $count, 'permissions' => $newPermissions],
                ['membership_id' => $membershipId, 'role' => $target->role, 'permission_count' => $before, 'permissions' => $target->permissions],
                subject: $subject,
                actor: $editor->membershipId,
            );
            $this->outbox->emit(AuditAction::AccessPermissionChanged, $subject, [
                'membership_id' => $membershipId,
                'role' => $newRole,
                'permission_count' => $count,
                'previous_permission_count' => $before,
                'added_count' => count(array_diff($newPermissions, $target->permissions)),
                'removed_count' => count(array_diff($target->permissions, $newPermissions)),
            ], actor: $editor->membershipId);
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
