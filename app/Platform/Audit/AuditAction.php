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
    case AccessWorkspaceForbidden = 'access.workspace.forbidden';
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
    case AccessAttributeKeyRenamed = 'access.attribute_key.renamed';

    // Connector: the Workspace host allowlist.
    case ConnectorHostAllowlistEntryCreated = 'connector.host_allowlist_entry.created';
    case ConnectorHostAllowlistEntryRemoved = 'connector.host_allowlist_entry.removed';

    // Connector: Data Sources (Story 2.3).
    case ConnectorDataSourceCreated = 'connector.data_source.created';
    case ConnectorDataSourceUpdated = 'connector.data_source.updated';

    // Connector: Endpoints of a Data Source (Story 2.9). Ids, the method, revision numbers, counts and keyed hashes only.
    case ConnectorEndpointCreated = 'connector.endpoint.created';
    case ConnectorEndpointRevised = 'connector.endpoint.revised';
    case ConnectorEndpointReadOnlyFlagSet = 'connector.endpoint.read_only_flag_set';

    // Connector: an Endpoint was tested (Story 2.10). The Endpoint id, the method and the revision tested only; never a value, the path or a body.
    case ConnectorEndpointTested = 'connector.endpoint.tested';

    // Connector: an Admin fetched an Endpoint as a member (Story 2.13). The Endpoint id, the target membership id and the revision only; never a value.
    case ConnectorFetchAsUserPerformed = 'connector.fetch_as_user.performed';

    // Connector: a credential set, replaced or removed (Story 2.4); the value is never part of the event, only its keyed hash.
    case ConnectorDataSourceSecretChanged = 'connector.data_source.secret_changed';

    // Connector: outbound guard (Story 2.2). A block is a security event; a grant is an operator action mirrored here.
    case ConnectorEgressBlocked = 'connector.egress.blocked';
    case ConnectorEgressGrantCreated = 'connector.egress_grant.created';
    case ConnectorEgressGrantRevoked = 'connector.egress_grant.revoked';

    // Ingestion: a sync target kept a new good response (Story 2.14). An outbox event only: IDs and sequence numbers, never the body.
    case IngestionPayloadChanged = 'ingestion.payload.changed';

    // Platform: operator actions mirrored into the Workspace audit log.
    case PlatformWorkspaceCreated = 'platform.workspace.created';

    // Platform: an asynchronous Operation ended (Story 2.5). An outbox event only: IDs and enums, never the summary.
    case PlatformOperationCompleted = 'platform.operation.completed';

    // Platform: someone asked the holder of an edit lock to flush its work before a take-over (Story 2.8). An outbox event only: IDs and enums.
    case PlatformEditLockFlushRequested = 'platform.edit_lock.flush_requested';

    // Platform: an edit lock was taken over (Story 2.8): the epoch rose and the lock changed hands. An outbox event only: IDs, an enum and a boolean.
    case PlatformEditLockTaken = 'platform.edit_lock.taken';

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
