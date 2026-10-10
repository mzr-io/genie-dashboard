<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Auto-map and the slot checklist of a Draft (Story 3.12a). {@see self::checklist()} is read-only (a viewer may call it); the three writes take the
 * Draft's row lock, compare the edit-lock epoch and claim as a sample write does (no `expected_revision`), change only `config`, and raise the revision.
 */
interface DraftSlots
{
    /**
     * @throws DraftNotFound
     * @throws PreviewRefused the Draft has no usable sample (`mapping.no_sample`)
     * @throws SampleUnavailable
     */
    public function checklist(DraftActor $actor, string $draftId): DraftChecklist;

    /**
     * Auto-maps again: recomputes every slot but the Admin's decisions and writes `config.query_plan` from every mapped slot.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function rerun(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Stores `config.slot_map[slot] = {path, state: confirmed}` for a slot that has a mapped field.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws SlotNotFound
     * @throws SlotRefused
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function confirm(DraftActor $actor, string $draftId, string $slot, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Stores `{path, state: overridden}` after checking the field is one scalar of the sample that fits the slot, or clears the slot with a null path.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws SlotNotFound
     * @throws SlotRefused
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function override(DraftActor $actor, string $draftId, string $slot, ?string $path, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;
}
