<?php

namespace App\Modules\Connector\Contracts;

/** A Data Source that a removed allowlist entry would block on its next call. */
final readonly class DependentDataSource
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
