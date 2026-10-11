<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Auto-map and the slot checklist of a Draft (Story 3.12a) and the mapping writes (Story 3.13c). {@see self::read()} is read-only (a viewer may call it);
 * every write takes the Draft's row lock, compares the edit-lock epoch and claim as a sample write does, then the caller's `$revision` against the Draft's
 * (a stale one is {@see DraftRevisionConflict}, nothing written), changes only `config` (recomputing `query_plan` and `mapped_sample_revision` from the
 * resulting mapping) and raises the revision. A write that changes nothing leaves the revision alone.
 */
interface DraftSlots
{
    /**
     * The one consistent read: checklist, roles, stored plan and both revisions in one pass. A Draft without a live sample reads as `expired` with its stored
     * decisions and plan (never a refusal).
     *
     * @throws DraftNotFound
     * @throws SampleUnavailable
     */
    public function read(DraftActor $actor, string $draftId): DraftMapping;

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
     * @throws DraftRevisionConflict
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function rerun(DraftActor $actor, string $draftId, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

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
     * @throws DraftRevisionConflict
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function confirm(DraftActor $actor, string $draftId, string $slot, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

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
     * @throws DraftRevisionConflict
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function override(DraftActor $actor, string $draftId, string $slot, ?string $path, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Puts back what an override changed (Story 3.13a): the slot's entry and the one role override the override wrote, exactly as `undo` (the payload an
     * override returned) holds them, then fills the unconfirmed slots from the restored roles (an auto-map re-run, which writes `config.query_plan`).
     * Confirmed and overridden slots are never changed by the re-fill.
     *
     * @param  array<string, mixed>  $undo  `{slot, slot_entry: {path, state}|null, role: {path, previous}|null, applied: {path, state}}`; `applied` is what the override wrote
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws SlotRefused an unknown or config-only slot, or a path to restore that the slot no longer accepts
     * @throws SlotChanged the slot's entry is no longer the one the override wrote (nothing is written)
     * @throws InvalidDraft a malformed entry or role (`blocks.invalid_config`)
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws DraftRevisionConflict
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function undo(DraftActor $actor, string $draftId, array $undo, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Sets the record path (a candidate of the sample) or clears it with null, re-maps and rewrites the plan. A decided slot whose path left the sample
     * stays stored and reads `api_changed`.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws InvalidDraft a path that is not a record path of the sample (`blocks.invalid_config`)
     * @throws DraftNotFound
     * @throws DraftPublished
     * @throws DraftLockLost
     * @throws DraftRevisionConflict
     * @throws PreviewRefused
     * @throws SampleUnavailable
     */
    public function setRecordPath(DraftActor $actor, string $draftId, ?string $recordPath, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Overrides a field's role, or clears the override with a null role; same refusals and effects as {@see self::setRecordPath()}.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws InvalidDraft an unknown role, or a path that is not a field of the sample (setting only)
     */
    public function setFieldRole(DraftActor $actor, string $draftId, string $path, ?string $role, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * "Treat as": sets a field's type (`text|number|boolean|date`), or clears it with null; same refusals and effects as {@see self::setFieldRole()}.
     *
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws InvalidDraft
     */
    public function setFieldType(DraftActor $actor, string $draftId, string $path, ?string $type, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist;

    /**
     * Stores the presentation rules (Story 3.15a): per-slot format, emphasis, direction and label, threshold bands and the empty display, judged by Mapping's
     * `Presentation` (every fault keyed `config.presentation.<path>`, nothing stored). Null or an empty object clears them. Same lock, revision and no-op
     * rules as the other mapping writes; it also needs the Draft's sample, as they do.
     *
     * @param  array<string, mixed>|null  $presentation
     * @param  (\Closure(): bool)|null  $holdsLock
     *
     * @throws InvalidDraft
     */
    public function setPresentation(DraftActor $actor, string $draftId, ?array $presentation, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftPresentation;
}
