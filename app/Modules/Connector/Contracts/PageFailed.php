<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;
use Throwable;

/**
 * A paged fetch stopped at one page (Story 2.11): `page` is the page that failed or that was refused, `cause` is the error
 * as the first request would have raised it (a block, a limit, not JSON, a transport error). A page that answers with a non-2xx status is not a PageFailed: the transport returns that answer (with `page` set) and the caller reports it as `http_N`. The whole fetch failed and
 * nothing is kept. Never a URL, a token or a body.
 */
final class PageFailed extends RuntimeException
{
    public readonly int $pages;

    /** @param  int|null  $pages  the pages fetched and read before the failure; default: the ones before `$page` */
    public function __construct(public readonly int $page, public readonly Throwable $cause, ?int $pages = null)
    {
        $this->pages = $pages ?? $page - 1;

        parent::__construct("Page {$page} failed.", 0, $cause);
    }
}
