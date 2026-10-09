<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The Drafts of Blocks (Story 3.3): start, save, discard and list. A Block has at most one Draft. Every call runs in the
 * Workspace's transaction under row-level security.
 */
interface Drafts
{
    /** The soft lock's resource type for a Draft (`Platform\EditLock`; the lock endpoints come with Story 3.6). */
    public const LOCK_TYPE = 'draft_block';

    /** The longest Draft name: the lenient Draft schema accepts a name and a Block Type, and nothing else. */
    public const NAME_MAX = 120;

    /** The Drafts of the Workspace (never a discarded one) whose name, type or category matches `$search`, newest edit first. */
    public function list(string $workspaceId, ?string $search): DraftPage;

    /**
     * Starts a Block and its first Draft (revision 1, epoch 1, version "1.0"), or, with `$blockId`, a new Draft of an existing Block that has none.
     *
     * @throws InvalidDraft an unknown Block Type, or a Block that does not exist or has another type
     * @throws DraftExists the Block already has a Draft
     */
    public function create(DraftActor $actor, string $name, string $type, ?string $blockId = null): Draft;

    /**
     * Saves the Draft's name and, when given, its Block Type: a compare-and-set on the epoch first, then on the revision. Writes no audit event.
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws DraftLockLost the epoch is stale
     * @throws DraftRevisionConflict the revision is stale
     * @throws InvalidDraft an unknown Block Type
     */
    public function save(DraftActor $actor, string $draftId, string $name, ?string $type, int $revision, int $lockEpoch): Draft;

    /**
     * Marks the Draft discarded and audits it; the rows are deleted later by the `maintenance` worker.
     *
     * @throws DraftNotFound the Draft does not exist or is already discarded
     */
    public function discard(DraftActor $actor, string $draftId): void;
}
