<?php

namespace App\Http\Navigation;

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Access\Contracts\UserMembership;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The navigation model of the two-area shell, shared with every Inertia page as the `shell` prop. The area
 * (`user` or `admin`) comes from the session; the client owns the copy and the icons (it maps `key`).
 *
 * An Admin item whose permission the person lacks is still listed, with `allowed` false: the shell shows it
 * disabled with its reason rather than hiding it. `ADMIN_ITEMS` is also the one route-to-permission mapping the
 * `admin` route middleware enforces (Story 1.19), so the menu and the gate cannot drift apart. `can` maps every
 * permission to whether the person holds it in the active Admin area, for actions the person cannot do
 * (rendered disabled with their reason, never hidden).
 */
final class ShellNavigation
{
    /** User area items: item key => route name. */
    public const USER_ITEMS = [
        'overview' => 'overview',
        'my-dashboards' => 'dashboards.index',
        'templates' => 'templates.index',
        'profile' => 'profile.edit',
        'help' => 'help',
    ];

    /**
     * Admin area items, in order: item key => [route name, the permission it needs (null: none)].
     * The planning documents name the permissions and the items but not which gates which, so this one
     * constant is the mapping, for the navigation and for the `admin` middleware alike.
     */
    public const ADMIN_ITEMS = [
        'admin-overview' => ['admin.overview', null],
        'block-management' => ['admin.blocks.index', Permission::BlocksEdit],
        'create-block' => ['admin.blocks.create', Permission::BlocksEdit],
        'draft-blocks' => ['admin.blocks.drafts', Permission::BlocksEdit],
        'published-blocks' => ['admin.blocks.published', Permission::BlocksEdit],
        'block-categories' => ['admin.categories.index', Permission::BlocksEdit],
        'dashboard-templates' => ['admin.templates.index', Permission::TemplatesManage],
        'data-sources' => ['admin.data-sources.index', Permission::DataSourcesManage],
        'user-configuration' => ['admin.users.index', Permission::UsersManage],
        'system-settings' => ['admin.settings.index', Permission::SettingsManage],
        'audit-log' => ['admin.audit.index', Permission::AuditView],
    ];

    /**
     * Admin page routes that are not navigation items (a view of a parent item): route name => the permission it needs.
     * The Groups view of User configuration (Story 1.23), the Host allowlist of System settings (Story 2.1) and the Data source form (Story 2.3) are such pages; their parents link to them.
     */
    public const ADMIN_PAGES = [
        'admin.users.groups' => Permission::UsersManage,
        'admin.settings.host-allowlist' => Permission::SettingsManage,
        'admin.data-sources.create' => Permission::DataSourcesManage,
        'admin.data-sources.edit' => Permission::DataSourcesManage,
        'admin.data-sources.endpoints' => Permission::DataSourcesManage,
    ];

    /**
     * Admin API routes (`/api/v1/admin/*`) that take their permission from the route name rather than from a
     * key on the middleware: route name => the permission it needs. They are not navigation items.
     */
    public const ADMIN_API_ROUTES = [
        'api.admin.members' => Permission::UsersManage,
        'api.admin.members.show' => Permission::UsersManage,
        'api.admin.members.update' => Permission::UsersManage,
        'api.admin.members.deactivate' => Permission::UsersManage,
        'api.admin.members.reactivate' => Permission::UsersManage,
        'api.admin.invitations.store' => Permission::UsersManage,
        'api.admin.invitations.resend' => Permission::UsersManage,
        'api.admin.invitations.destroy' => Permission::UsersManage,
        'api.admin.groups.index' => Permission::UsersManage,
        'api.admin.groups.store' => Permission::UsersManage,
        'api.admin.groups.update' => Permission::UsersManage,
        'api.admin.groups.destroy' => Permission::UsersManage,
        'api.admin.groups.members.store' => Permission::UsersManage,
        'api.admin.groups.members.destroy' => Permission::UsersManage,
        'api.admin.host-allowlist.index' => Permission::SettingsManage,
        'api.admin.host-allowlist.store' => Permission::SettingsManage,
        'api.admin.host-allowlist.destroy' => Permission::SettingsManage,
        'api.admin.host-allowlist.dependents' => Permission::SettingsManage,
        'api.admin.data-sources.index' => Permission::DataSourcesManage,
        'api.admin.data-sources.store' => Permission::DataSourcesManage,
        'api.admin.data-sources.check-url' => Permission::DataSourcesManage,
        'api.admin.data-sources.test-connection' => Permission::DataSourcesManage,
        'api.admin.data-sources.show' => Permission::DataSourcesManage,
        'api.admin.data-sources.update' => Permission::DataSourcesManage,
        'api.admin.data-sources.endpoints.index' => Permission::DataSourcesManage,
        'api.admin.data-sources.endpoints.store' => Permission::DataSourcesManage,
        'api.admin.data-sources.endpoints.show' => Permission::DataSourcesManage,
        'api.admin.data-sources.endpoints.update' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.acquire' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.heartbeat' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.release' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.takeover' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.takeover.status' => Permission::DataSourcesManage,
        'api.admin.data-sources.lock.flush' => Permission::DataSourcesManage,
    ];

    /** Whether the route name is an Admin item or Admin API route (the gate fails closed for any other route without a key). */
    public static function hasRoute(string $route): bool
    {
        foreach (self::ADMIN_ITEMS as [$name]) {
            if ($name === $route) {
                return true;
            }
        }

        return array_key_exists($route, self::ADMIN_PAGES) || array_key_exists($route, self::ADMIN_API_ROUTES);
    }

    /** The permission an Admin route needs (null: the Admin area only, or not an Admin item). */
    public static function permissionForRoute(string $route): ?Permission
    {
        foreach (self::ADMIN_ITEMS as [$name, $permission]) {
            if ($name === $route) {
                return $permission;
            }
        }

        return self::ADMIN_PAGES[$route] ?? self::ADMIN_API_ROUTES[$route] ?? null;
    }

    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly MembershipPermissions $permissions,
    ) {}

    /**
     * @return array{area: string, membership_id: string|null, workspace: array{id: string, name: string, label: string|null}|null, role: string|null, workspaces: list<array{id: string, name: string, label: string|null, role: string}>, can: array<string, bool>, items: list<array{key: string, href: string, permission: string|null, allowed: bool}>, switch_href: string, help_href: string, profile_href: string, sign_out_href: string}
     */
    public function for(Request $request): array
    {
        $user = $request->user();
        $area = $request->hasSession() && $request->session()->get('area') === 'admin' ? 'admin' : 'user';
        $workspaceId = $request->hasSession() ? $request->session()->get(WorkspaceTransaction::SESSION_KEY) : null;
        $workspaceId = is_string($workspaceId) && Str::isUuid($workspaceId) ? strtolower($workspaceId) : null;

        $workspace = null;
        $role = null;
        $held = [];
        $memberships = $user instanceof User ? $this->memberships($user->id) : [];

        if ($workspaceId !== null) {
            [$workspace, $role] = $this->membership($memberships, $workspaceId);
        }

        // The navigation follows the active membership, not just the session: the Admin navigation needs an
        // active Admin membership (a demotion or deactivation after sign-in takes effect on the next request);
        // a User sees the User navigation; with no active membership there are no items (Help & support and
        // Sign out remain in the footer).
        $effective = $workspace === null ? null : ($area === 'admin' && $role === 'admin' ? 'admin' : 'user');

        if ($effective === 'admin' && $user instanceof User) {
            $held = $this->held($user->id, $workspace['id']);
        }

        return [
            'area' => $effective ?? $area,
            // The person's own active membership in the Workspace (the Roles & permissions editor refuses its own row).
            'membership_id' => $workspace === null ? null : $this->membershipId($memberships, $workspaceId),
            'workspace' => $workspace,
            'role' => $role,
            'workspaces' => $this->workspaces($memberships),
            'can' => $this->can($held),
            'switch_href' => route('workspaces.switch', absolute: false),
            'items' => match ($effective) {
                'admin' => $this->adminItems($held),
                'user' => $this->userItems(),
                default => [],
            },
            'help_href' => route('help', absolute: false),
            'profile_href' => route('profile.edit', absolute: false),
            'sign_out_href' => route('logout', absolute: false),
        ];
    }

    /**
     * Every membership of the person (the Access SECURITY DEFINER lookup), read once per request; a failed
     * read means no Workspace and no switcher rather than a failed page.
     *
     * @return list<UserMembership>
     */
    private function memberships(int $userId): array
    {
        try {
            return $this->memberships->forUser($userId);
        } catch (Throwable) {
            Log::error('shell.membership.failed');

            return [];
        }
    }

    /**
     * @param  list<UserMembership>  $memberships
     * @return array{0: array{id: string, name: string, label: string|null}|null, 1: string|null}
     */
    private function membership(array $memberships, string $workspaceId): array
    {
        foreach ($memberships as $membership) {
            if ($membership->workspaceId === $workspaceId && $membership->status === 'active' && $membership->workspaceStatus === 'active') {
                return [['id' => $membership->workspaceId, 'name' => $membership->workspaceName, 'label' => $membership->workspaceLabel], $membership->role];
            }
        }

        return [null, null];
    }

    /** @param  list<UserMembership>  $memberships */
    private function membershipId(array $memberships, ?string $workspaceId): ?string
    {
        foreach ($memberships as $membership) {
            if ($membership->workspaceId === $workspaceId && $membership->status === 'active' && $membership->workspaceStatus === 'active') {
                return $membership->membershipId;
            }
        }

        return null;
    }

    /**
     * The Workspaces the person may switch to: usable memberships only (active membership in an active
     * Workspace), in Workspace-name order. The label is cosmetic text, shown verbatim.
     *
     * @param  list<UserMembership>  $memberships
     * @return list<array{id: string, name: string, label: string|null, role: string}>
     */
    private function workspaces(array $memberships): array
    {
        $list = [];

        foreach ($memberships as $membership) {
            if ($membership->status === 'active' && $membership->workspaceStatus === 'active') {
                $list[] = [
                    'id' => $membership->workspaceId,
                    'name' => $membership->workspaceName,
                    'label' => $membership->workspaceLabel,
                    'role' => $membership->role,
                ];
            }
        }

        // Case-insensitive by name (the lookup's own order is the database collation's); ID breaks ties.
        usort($list, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['name'], $a['id']] <=> [mb_strtolower($b['name']), $b['name'], $b['id']]);

        return $list;
    }

    /**
     * @param  list<Permission>  $held  empty unless the active area is Admin
     * @return array<string, bool>
     */
    private function can(array $held): array
    {
        $can = [];

        foreach (Permission::cases() as $permission) {
            $can[$permission->value] = in_array($permission, $held, true);
        }

        return $can;
    }

    /**
     * @return list<Permission>
     */
    private function held(int $userId, string $workspaceId): array
    {
        try {
            return $this->permissions->forUser($userId, $workspaceId);
        } catch (Throwable) {
            // The menu fails closed: every gated item shows disabled rather than the page failing.
            Log::error('shell.permissions.failed');

            return [];
        }
    }

    /**
     * @return list<array{key: string, href: string, permission: string|null, allowed: bool}>
     */
    private function userItems(): array
    {
        $items = [];

        foreach (self::USER_ITEMS as $key => $route) {
            $items[] = ['key' => $key, 'href' => route($route, absolute: false), 'permission' => null, 'allowed' => true];
        }

        return $items;
    }

    /**
     * @param  list<Permission>  $held
     * @return list<array{key: string, href: string, permission: string|null, allowed: bool}>
     */
    private function adminItems(array $held): array
    {
        $items = [];

        foreach (self::ADMIN_ITEMS as $key => [$route, $permission]) {
            $items[] = [
                'key' => $key,
                'href' => route($route, absolute: false),
                'permission' => $permission?->value,
                'allowed' => $permission === null || in_array($permission, $held, true),
            ];
        }

        return $items;
    }
}
