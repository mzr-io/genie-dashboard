<?php

namespace App\Platform\Operations;

/**
 * The work of one Operation kind. A module registers it (the kernel calls no module); it runs on the queue the kind
 * names, inside the requester's Workspace transaction.
 */
interface OperationHandler
{
    /**
     * Does the work and reports the outcome. `$input` is what the requester passed to {@see Operations::enqueue}: small
     * and never a secret (a secret travels as a transient row owned by the Operation).
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(Operation $operation, array $input): OperationOutcome;

    /**
     * Called once the work has ended, whatever its outcome (even when `handle` threw): removes whatever the Operation
     * staged, such as transient secrets.
     */
    public function cleanup(Operation $operation): void;
}
