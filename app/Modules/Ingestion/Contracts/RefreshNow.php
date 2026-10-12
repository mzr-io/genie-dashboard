<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * Asks for a sync target to be fetched at its next dispatcher tick instead of at its next due time (Story 4.6, a User's manual refresh). It never
 * calls a source itself: only the dispatcher schedules fetches, under its own budgets, so the fairness and rate rules of Story 2.17 and 2.19 still
 * hold. Runs in the caller's Workspace transaction.
 */
interface RefreshNow
{
    /** @return bool whether a live target was brought forward */
    public function now(string $workspaceId, string $syncTargetId): bool;

    /** The seconds one member must wait between manual refreshes of a target, or null when the deployment sets no window. */
    public function windowSeconds(): ?int;
}
