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
     * One Draft with its `config` (Story 3.4), for the wizard. Creates the Workspace's "General" category first when it is missing.
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     */
    public function find(string $workspaceId, string $draftId): Draft;

    /**
     * Saves the Draft's name and, when given, its Block Type, category, `config` and wizard step: a compare-and-set on the epoch first,
     * then on the revision. Writes no audit event. A `config` replaces the stored one and is validated against the Block Type's registry
     * limits (Story 3.4); a field left null is left as it is.
     *
     * @param  array<mixed>|null  $config
     * @param  array<mixed>|null  $uiState  the wizard's own state (Story 3.5), stored in `draft_ui_state.state`; never part of the version's config
     *
     * @throws DraftNotFound the Draft does not exist or was discarded
     * @throws DraftPublished the version is no longer a Draft (it was published)
     * @throws DraftLockLost the epoch is stale
     * @throws DraftRevisionConflict the revision is stale
     * @throws InvalidDraft an unknown Block Type or category, or a config outside the limits
     */
    public function save(DraftActor $actor, string $draftId, string $name, ?string $type, int $revision, int $lockEpoch, ?array $config = null, ?int $step = null, ?string $categoryId = null, ?array $uiState = null): Draft;

    /**
     * Marks the Draft discarded and audits it; the rows are deleted later by the `maintenance` worker.
     *
     * @throws DraftNotFound the Draft does not exist or is already discarded
     */
    public function discard(DraftActor $actor, string $draftId): void;
}
