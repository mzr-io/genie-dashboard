<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\SourceGovernor;

/** What {@see EvaluateSourceHealth::decide()} judges (Story 2.18): plain values already read, so the decision does no I/O. */
final readonly class HealthEvidence
{
    public function __construct(
        /** A probe has produced a result. */
        public bool $probed,
        /** The latest probe's result (meaningful only when `probed`). */
        public bool $probeOk,
        /** The Data Source has at least one final (succeeded or failed) scheduled run. */
        public bool $hasFinalRun,
        /** `closed`, `open` or `half_open` ({@see SourceGovernor::state()}). */
        public string $breaker,
        /** Failed runs since the last succeeded one. */
        public int $trailingFailures,
        /** Final runs within the window. */
        public int $succeeded,
        public int $failed,
    ) {}
}
