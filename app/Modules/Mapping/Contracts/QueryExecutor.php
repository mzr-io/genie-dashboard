<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Platform\Json\LosslessJson;

/**
 * The port the Query Plan engine runs a plan through (AD-12). The in-process executor is the only one today; the engine does not care where the
 * stages run. An executor reads the document only through the platform's lossless tree, keeps every number as the digits received, and aborts
 * (never truncates) when a guard is passed.
 */
interface QueryExecutor
{
    /**
     * @param  array<string, mixed>  $plan  a plan already judged by {@see QueryPlan::validate()}
     * @param  mixed  $document  the tree {@see LosslessJson} made
     * @param  array{start: string, end: string}|null  $period  both dates inclusive, Workspace-local
     *
     * @throws GuardExceeded
     */
    public function execute(array $plan, mixed $document, BlockType $type, PlanGuards $guards, ?array $period = null): ExecutionResult;
}
