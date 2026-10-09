<?php

namespace App\Modules\Access\Contracts;

/**
 * Error codes owned by Access: `access.{snake_case}`. A generated TypeScript enum
 * (`resources/js/types/error-codes.ts`) mirrors every module's codes.
 */
enum ErrorCode: string
{
    case AttributesUnavailable = 'access.attributes_unavailable';
    case ContextMissing = 'access.context_missing';
    case InvitationsNotConfigured = 'access.invitations_not_configured';
    case LastUsersManageHolder = 'access.last_users_manage_holder';
    case NotAuthorized = 'access.not_authorized';
    case PermissionNotHeld = 'access.permission_not_held';
    case RevisionConflict = 'access.revision_conflict';
    case SelfChangeForbidden = 'access.self_change_forbidden';
    case WorkspaceForbidden = 'access.workspace_forbidden';
}
