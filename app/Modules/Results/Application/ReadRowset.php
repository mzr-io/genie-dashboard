<?php

namespace App\Modules\Results\Application;

use App\Modules\Results\Contracts\RowsetReads;
use App\Modules\Results\Contracts\RowsetStore;
use App\Modules\Results\Contracts\RowsPage;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Platform\Tenancy\TenantCache;

/**
 * {@see RowsetReads}. The Block's current result names its set (`rowset_ref`); the set is cut to the page asked for, the last page when it is past the
 * end. A set that is gone (expired, flushed) queues one compute for the target, debounced like a read's, and is `expired`: nothing here reaches a source.
 */
final class ReadRowset implements RowsetReads
{
    private const COMPUTE_DEBOUNCE_SECONDS = 10;

    public const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly ViewedResults $results,
        private readonly RowsetStore $rowsets,
        private readonly TenantCache $cache,
    ) {}

    public function read(ViewRequest $request, int $page, int $pageSize): RowsPage
    {
        $pageSize = min(max($pageSize, 1), self::MAX_PAGE_SIZE);
        $page = max($page, 1);
        $workspaceId = strtolower($request->workspaceId);
        $viewed = $this->results->view($request);
        $result = $viewed->result;

        if ($result === null) {
            return new RowsPage(RowsPage::UNAVAILABLE, page: $page, pageSize: $pageSize);
        }

        if ($result->rowsetRef === null) {
            return new RowsPage(RowsPage::EMPTY, page: 1, pageSize: $pageSize, dataAsOf: $result->dataAsOf, resultEtag: $result->resultEtag);
        }

        $set = $this->rowsets->get($workspaceId, $result->rowsetRef);

        if ($set === null) {
            if ($viewed->syncTargetId !== null && $this->cache->lock($workspaceId, 'compute-queued:'.$viewed->syncTargetId, self::COMPUTE_DEBOUNCE_SECONDS)->get()) {
                ComputeResultsJob::dispatch($workspaceId, $viewed->syncTargetId)->afterCommit();
            }

            return new RowsPage(RowsPage::EXPIRED, page: $page, pageSize: $pageSize, dataAsOf: $result->dataAsOf, resultEtag: $result->resultEtag);
        }

        $keys = array_column($set['columns'], 'key');
        $total = 0;

        foreach ($keys as $key) {
            $total = max($total, count($set['values'][$key] ?? []));
        }

        if ($total === 0) {
            return new RowsPage(RowsPage::EMPTY, $set['columns'], [], 0, 1, $pageSize, 0, false, $result->dataAsOf, $result->resultEtag);
        }

        $pages = (int) ceil($total / $pageSize);
        $clamped = $page > $pages;
        $page = min($page, $pages);
        $from = ($page - 1) * $pageSize;
        $rows = [];

        for ($i = $from; $i < min($from + $pageSize, $total); $i++) {
            $row = [];

            foreach ($keys as $key) {
                $row[] = $set['values'][$key][$i] ?? null;
            }

            $rows[] = $row;
        }

        return new RowsPage(RowsPage::OK, $set['columns'], $rows, $total, $page, $pageSize, $pages, $clamped, $result->dataAsOf, $result->resultEtag);
    }
}
