<?php

namespace App\Modules\Results\Contracts;

/**
 * What a read of a viewed Block gives (Story 4.3): the stored result with `stale` and `refreshing` set, or a `pending` placeholder when none is stored
 * yet (`result` is then null and `state` says why). `hotUntil` is when the subscription keeps the data hot until; `hotRefusal` is the reason the Block
 * is not kept hot (a budget, no period, an unset setting) while its last result is still served. `staleAt` is when it turns Stale.
 */
final readonly class ViewedResult
{
    public function __construct(
        public ResultState $state,
        public ?BlockResult $result,
        public ?string $staleAt,
        public ?string $hotUntil,
        public ?string $hotRefusal,
    ) {}
}
