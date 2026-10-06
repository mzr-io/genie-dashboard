<?php

namespace App\Platform\Audit;

use InvalidArgumentException;

/**
 * The closed set of audit actions and outbox event types (AD-29). Every value is
 * `{module}.{noun}.{past_verb}`: lowercase, three dot-separated parts. Add a case when a story needs
 * a new action; a string that is not a case is rejected everywhere.
 */
enum AuditAction: string
{
    /** `{module}.{noun}.{past_verb}`, each part lowercase letters, digits and underscores. */
    public const GRAMMAR = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    // Identity: sign-in outcomes, sessions, passwords, invitations.
    case IdentityInvitationAccepted = 'identity.invitation.accepted';
    case IdentityInvitationRejected = 'identity.invitation.rejected';
    case IdentitySigninSucceeded = 'identity.signin.succeeded';
    case IdentitySigninFailed = 'identity.signin.failed';
    case IdentitySigninThrottled = 'identity.signin.throttled';
    case IdentitySignoutCompleted = 'identity.signout.completed';
    case IdentityPasswordReset = 'identity.password.reset';
    case IdentityPasswordChanged = 'identity.password.changed';
    case IdentityWorkspaceSwitched = 'identity.workspace.switched';
    case IdentityAreaDenied = 'identity.area.denied';
    case IdentitySessionExtended = 'identity.session.extended';

    // Access: memberships, roles, permissions, groups, attributes and denials.
    case AccessAdminDenied = 'access.admin.denied';
    case AccessMembershipInvited = 'access.membership.invited';
    case AccessMembershipChanged = 'access.membership.changed';
    case AccessMembershipRemoved = 'access.membership.removed';
    case AccessMembershipDeactivated = 'access.membership.deactivated';
    case AccessMembershipReactivated = 'access.membership.reactivated';
    case AccessRoleChanged = 'access.role.changed';
    case AccessPermissionChanged = 'access.permission.changed';
    case AccessGroupChanged = 'access.group.changed';
    case AccessAttributeChanged = 'access.attribute.changed';
    case AccessAttributeKeyCreated = 'access.attribute_key.created';

    // Platform: operator actions mirrored into the Workspace audit log.
    case PlatformWorkspaceCreated = 'platform.workspace.created';

    /** The case for `$action`, or an exception: an unknown string never reaches storage. */
    public static function fromString(string $action): self
    {
        return self::tryFrom($action)
            ?? throw new InvalidArgumentException('Unknown audit action: '.self::printable($action));
    }

    /** The owning module, the first part of the action (`access`). */
    public function module(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    private static function printable(string $value): string
    {
        return json_encode(mb_substr($value, 0, 80), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) ?: '""';
    }
}
