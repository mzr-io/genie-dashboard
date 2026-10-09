<?php

namespace App\Modules\Connector\Contracts;

/** The one place a name is resolved. Tests fake it, so no test touches the network. */
interface HostResolver
{
    /**
     * Every A and AAAA record of the host, as text; an empty list when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
