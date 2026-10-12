<?php

namespace App\Modules\Results\Contracts;

/**
 * The read path of a Block Result (Story 4.3). It subscribes (or touches the subscription of) the Block's data so a viewed Block stays hot, then
 * reads the stored result and judges its freshness. It never calls a source: a source that is down changes nothing here. Runs in the caller's
 * Workspace transaction.
 */
interface ViewedResults
{
    public function view(ViewRequest $request): ViewedResult;
}
