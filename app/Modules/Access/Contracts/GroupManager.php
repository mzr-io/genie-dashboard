<?php

namespace App\Modules\Access\Contracts;

/**
 * An Admin organises members into groups (Story 1.23), in the caller's Workspace transaction. Every change that
 * happens writes `access.group.changed` to the audit log and the outbox in the same transaction; a no-op (adding a
 * member who is in the group, removing one who is not) writes nothing. Nothing is cached: membership changes apply on
 * the next request. Groups only group in this epic: deleting one removes its memberships and nothing else.
 */
interface GroupManager
{
    /**
     * @return GroupRow the new group
     *
     * @throws GroupNameTaken
     */
    public function create(MemberEditor $editor, string $name): GroupRow;

    /**
     * @return GroupRow the group as renamed
     *
     * @throws GroupNotFound
     * @throws GroupNameTaken
     */
    public function rename(MemberEditor $editor, string $groupId, string $name): GroupRow;

    /**
     * @return int the number of memberships removed with the group
     *
     * @throws GroupNotFound
     */
    public function delete(MemberEditor $editor, string $groupId): int;

    /**
     * @return GroupRow the group after the change (unchanged when the member was already in it)
     *
     * @throws GroupNotFound
     * @throws MembershipNotFound
     */
    public function addMember(MemberEditor $editor, string $groupId, string $membershipId): GroupRow;

    /**
     * @return GroupRow the group after the change; a membership that is not in the group (or not in this Workspace) is a no-op
     *
     * @throws GroupNotFound
     * @throws MembershipNotFound a malformed membership ID
     */
    public function removeMember(MemberEditor $editor, string $groupId, string $membershipId): GroupRow;
}
