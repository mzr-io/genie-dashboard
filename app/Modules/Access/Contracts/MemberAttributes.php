<?php

namespace App\Modules\Access\Contracts;

/**
 * A member's user attribute values (Story 2.12). Values are sealed and carry a blind index; the plain value is read only
 * by an Admin with `users.manage`, never logged, audited (a keyed hash only) or put in an outbox event (IDs only).
 */
interface MemberAttributes
{
    /**
     * Every defined key with the member's value.
     *
     * @return list<AttributeValueRow>
     *
     * @throws MembershipNotFound
     * @throws AttributesUnavailable the `data` or `digest` key is unusable, or a stored value cannot be opened
     */
    public function forMember(string $workspaceId, string $membershipId): array;

    /**
     * Sets the supplied keys (an absent key is unchanged; there is no way to clear a value). Setting an identical value
     * changes, audits and emits nothing.
     *
     * @param  array<array-key, mixed>  $values  key id => value
     * @return list<string> the key ids that changed
     *
     * @throws SelfChangeForbidden the editor's own membership
     * @throws MembershipNotFound
     * @throws AttributeValueInvalid an undefined key, or a value that is empty or fails the key's type; nothing is stored
     * @throws AttributesUnavailable the `data` or `digest` key is unusable; nothing is stored
     */
    public function set(MemberEditor $editor, string $membershipId, array $values): array;
}
