<?php

namespace App\Modules\Results\Contracts;

/**
 * The paginated rows of a viewed Block (Story 5.9). Access is the caller's (Dashboards checks the member can see the published Block on every request);
 * this reads only what Results holds and never calls a source: a missing set queues a compute and answers `expired`.
 */
interface RowsetReads
{
    public function read(ViewRequest $request, int $page, int $pageSize): RowsPage;
}
