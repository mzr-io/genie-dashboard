<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * Registers or touches a subscription (Story 2.19; FR-13, AD-7): somebody is watching the data of an Endpoint, so its sync target is hot.
 * The caller (a Block version, in a later epic) names the Endpoint, the Block version, the role, its own refresh interval, an opaque compute
 * context, the resolved period and, for user-bound data, the values resolved for the member.
 *
 * It finds or creates the one sync target the fetch key names and upserts a `sync_subscriptions` row: the interval is copied at subscribe time, a
 * touch moves `last_access_at` only once the subscription's own refresh interval has passed since the last move, and
 * `hot_until = last_access_at + sync.hot_window` (null while that setting is unset). A missing or empty bound value creates no target and returns
 * {@see FetchKeyResult::CONTEXT_MISSING}; a new per-user target over the member's hourly budget is not created and is returned as budget-limited.
 *
 * Runs in the caller's Workspace transaction. Nothing here calls a source: only the dispatcher schedules fetches.
 */
interface Subscribe
{
    public function subscribe(SubscribeInput $input): SubscribeResult;
}
