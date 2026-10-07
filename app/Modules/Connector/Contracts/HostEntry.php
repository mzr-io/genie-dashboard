<?php

namespace App\Modules\Connector\Contracts;

/** One approved host of a Workspace. */
final readonly class HostEntry
{
    /**
     * @param  string  $addedBy  the membership that added it (the page resolves the name through Access)
     * @param  string  $createdAt  ISO 8601, UTC
     */
    public function __construct(
        public string $id,
        public string $host,
        public string $scheme,
        public int $port,
        public string $addedBy,
        public string $createdAt,
    ) {}
}
