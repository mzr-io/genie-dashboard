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
     * The field picker of one slot (Story 3.13a): every field of the sample with `compatible` and a `reason` when it is not, plus `static_text` for a text
     * slot. Read-only, like the checklist.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SlotNotFound
     * @throws DraftNotFound
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function fields(DraftActor $actor, string $draftId, string $slot): array;

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

    /**
     * Puts back what an override changed (Story 3.13a): the slot's entry and the one role override the override wrote, exactly as `undo` (the payload an
     * override returned) holds them, then fills the unconfirmed slots from the restored roles (an auto-map re-run, which writes `config.query_plan`).
     * Confirmed and overridden slots are never changed by the re-fill.
     *
     * @param  array<string, mixed>  $undo  `{slot, slot_entry: {path, state}|null, role: {path, previous}|null, applied: {path, state}}`; `applied` is what the override wrote
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws SlotRefused an unknown or config-only slot, or a path to restore that the slot no longer accepts
     * @throws DraftRevisionConflict the slot's entry is no longer the one the override wrote (nothing is written)
     * @throws InvalidDraft a malformed entry or role (`blocks.invalid_config`)
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function undo(DraftActor $actor, string $draftId, array $undo, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;
}
