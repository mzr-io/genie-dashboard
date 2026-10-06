<?php

namespace App\Http\Navigation;

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
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
 * disabled with its reason rather than hiding it. A page request is not gated here (Story 1.19 enforces).
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
     * constant is the mapping; Story 1.19 may refine it.
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

    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly MembershipPermissions $permissions,
    ) {}

    /**
     * @return array{area: string, workspace: array{id: string, name: string}|null, role: string|null, items: list<array{key: string, href: string, permission: string|null, allowed: bool}>, help_href: string, profile_href: string, sign_out_href: string}
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

        if ($user instanceof User && $workspaceId !== null) {
            [$workspace, $role] = $this->membership($user->id, $workspaceId);
        }

        // The navigation follows the active membership, not just the session: the Admin navigation needs an
        // active Admin membership (a demotion or deactivation after sign-in takes effect on the next request);
        // a User sees the User navigation; with no active membership there are no items (Help & support and
        // Sign out remain in the footer).
        $effective = $workspace === null ? null : ($area === 'admin' && $role === 'admin' ? 'admin' : 'user');

        if ($effective === 'admin' && $user instanceof User && $workspaceId !== null) {
            $held = $this->held($user->id, $workspaceId);
        }

        return [
            'area' => $effective ?? $area,
            'workspace' => $workspace,
            'role' => $role,
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
     * @return array{0: array{id: string, name: string}|null, 1: string|null}
     */
    private function membership(int $userId, string $workspaceId): array
    {
        try {
            foreach ($this->memberships->forUser($userId) as $membership) {
                if ($membership->workspaceId === $workspaceId && $membership->status === 'active') {
                    return [['id' => $membership->workspaceId, 'name' => $membership->workspaceName], $membership->role];
                }
            }
        } catch (Throwable) {
            Log::error('shell.membership.failed');
        }

        return [null, null];
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
