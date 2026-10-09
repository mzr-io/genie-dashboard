<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressReason;

final class RecordingBlocks implements EgressBlockLog
{
    /** @var list<array{string, EgressReason, ?string, ?int}> */
    public array $recorded = [];

    public function record(string $workspaceId, EgressReason $reason, ?string $host, ?int $port): void
    {
        $this->recorded[] = [$workspaceId, $reason, $host, $port];
    }
}
