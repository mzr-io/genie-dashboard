<?php

namespace App\Modules\Access\Application;

use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deletes a member's user attribute values when their membership is removed (Story 2.12): it handles
 * `access.membership.removed` inside the event's Workspace transaction and calls the definer function
 * `access_delete_member_attributes` (role `app` has no DELETE on tenant tables). The membership ID is the event's
 * `membership_id`, or the ID of its `membership:{id}` subject. The removal flow itself belongs to a later story.
 */
final class AttributesOnMembershipRemoved implements OutboxConsumer
{
    public const NAME = 'access.attributes_on_membership_removed';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return $event->type === AuditAction::AccessMembershipRemoved->value;
    }

    public function handle(OutboxEnvelope $event): void
    {
        $membershipId = $event->data['membership_id'] ?? null;

        if (! is_string($membershipId)) {
            $membershipId = explode(':', $event->subject, 2)[1] ?? '';
        }

        // An event without a membership to name has nothing to delete.
        if (! Str::isUuid($membershipId)) {
            return;
        }

        DB::selectOne('select access_delete_member_attributes(?::uuid) as removed', [strtolower($membershipId)]);
    }
}
