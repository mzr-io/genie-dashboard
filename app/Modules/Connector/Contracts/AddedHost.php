<?php

namespace App\Modules\Connector\Contracts;

/** The entry an Admin added and the list's revision after the change. */
final readonly class AddedHost
{
    public function __construct(
        public HostEntry $entry,
        public int $revision,
    ) {}
}
