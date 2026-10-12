<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Publishes a validated Draft as the Block's version (Story 3.19). Two transactions: the first freezes the Draft (`draft` to `prepared`, its `config`
 * becomes the immutable document, the Endpoint revision of the passed report is pinned) and emits `blocks.version.prepared`; the second activates it
 * (`prepared` to `published`, the Block's current version is switched, the Block is registered as an Access subject for All users, the Draft's samples
 * are ended, audit and `blocks.version.published`). A version left `prepared` is activated by the maintenance job.
 */
interface BlockPublishing
{
    /**
     * @param  (\Closure(): bool)|null  $holdsLock  checks, inside the transaction, that the caller's lock token still holds the soft lock
     *
     * @throws DraftNotFound the Draft is gone or was discarded
     * @throws DraftPublished the version is no longer a Draft (another request published it first)
     * @throws DraftLockLost the edit lock was lost or taken over
     * @throws PublishRefused there is no passed report bound to the Draft's current revision
     */
    public function publish(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock = null): PublishedVersion;
}
