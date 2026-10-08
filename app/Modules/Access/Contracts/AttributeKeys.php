<?php

namespace App\Modules\Access\Contracts;

/**
 * The Workspace's catalogue of user attribute keys (Story 2.12), in the caller's Workspace transaction. A key id and a
 * value type are fixed at creation; only the label changes, under optimistic concurrency. Creating and renaming write
 * `access.attribute_key.created` and `access.attribute_key.renamed` to the audit log in the same transaction. There is
 * no delete.
 */
interface AttributeKeys
{
    /** @return list<AttributeKeyRow> ordered by key id; `$search` matches the key id or the label, case-insensitively */
    public function list(string $workspaceId, ?string $search = null): array;

    public function count(string $workspaceId): int;

    /**
     * @throws AttributeKeyTaken the key id or the label is already used
     */
    public function create(MemberEditor $editor, string $keyId, string $label, string $valueType): AttributeKeyRow;

    /**
     * @return AttributeKeyRow the key as renamed (unchanged, and not audited, when the label is the same)
     *
     * @throws AttributeKeyNotFound
     * @throws AttributeKeyRevisionConflict
     * @throws AttributeKeyTaken the label is used by another key
     */
    public function rename(MemberEditor $editor, string $keyId, string $label, int $revision): AttributeKeyRow;
}
