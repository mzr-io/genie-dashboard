<?php

namespace App\Modules\Access\Contracts;

/**
 * The only way another module reads a member's own data to bind it into a request (Story 2.13). The values come from server-side
 * membership data alone: the membership ID, the sign-in email (Identity), the name of the member's one group and the decrypted
 * user attribute values (Story 2.12). It never takes a value from a client and it fails closed: a value that cannot be produced is
 * reported by name in {@see UserContextValues::$missing}, and no value is guessed, defaulted or blanked.
 *
 * The values are returned to the caller's process only: the caller must keep them out of every log, audit row, outbox event,
 * `sync_runs` row, Operation summary and Inertia prop.
 */
interface UserContext
{
    public const USER_ID = 'user_id';

    public const USER_EMAIL = 'user_email';

    public const USER_GROUP = 'user_group';

    public const USER_ATTRIBUTE = 'user_attribute';

    /**
     * @param  array<string, array{binding: string, key: string|null}>  $bindings  a caller-chosen reference => the binding kind and, for `user_attribute`, the defined key id
     *
     * @throws MembershipNotFound the membership is not an active member of the Workspace (row-level security hides every other)
     * @throws AttributesUnavailable an attribute is bound and the `data` key is unusable or a stored value cannot be opened
     */
    public function resolve(string $workspaceId, string $membershipId, array $bindings): UserContextValues;
}
