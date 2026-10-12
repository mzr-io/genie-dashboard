<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The `data.preview_as_user` check of every request that fetches or previews as another member (Stories 2.13, 3.17). Without the permission
 * the request is a 403 `access.not_authorized` and a security event (`access.admin_denied`, with the permission and the route) is recorded
 * before any field is read, so an API attempt is never silent whatever the UI showed.
 */
trait ChecksPreviewAsUser
{
    /** Whether the caller holds `data.preview_as_user`; when not, the denial is recorded as a security event. */
    protected function mayPreviewAsUser(): bool
    {
        $user = $this->user();
        $workspaceId = $this->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! $user instanceof User || ! is_string($workspaceId)) {
            return false;
        }

        $workspaceId = strtolower($workspaceId);

        if (in_array(Permission::DataPreviewAsUser, app(MembershipPermissions::class)->forUser($user->id, $workspaceId), true)) {
            return true;
        }

        $membershipId = null;

        foreach (app(MembershipLookup::class)->forUser($user->id) as $membership) {
            if ($membership->workspaceId === $workspaceId) {
                $membershipId = $membership->membershipId;
            }
        }

        app(Audit::class)->recordSecurityEvent(
            AuditAction::AccessAdminDenied,
            [
                'user_id' => $user->id,
                'membership_id' => $membershipId,
                'area' => 'admin',
                'route' => $this->route()?->getName() ?? 'unnamed',
                'permission' => Permission::DataPreviewAsUser->value,
                'reason' => 'permission',
            ],
            $workspaceId,
            subject: $membershipId === null ? null : 'membership:'.$membershipId,
            actor: $membershipId,
        );

        return false;
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::NotAuthorized->value, 403, 'Forbidden'));
    }
}
