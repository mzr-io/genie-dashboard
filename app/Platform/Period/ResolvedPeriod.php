<?php

namespace App\Platform\Period;

/**
 * A resolved period: the preset `token` and the half-open window `[start, end)` as Workspace-local dates (`Y-m-d`). `comparison` is the
 * previous window (same shape, no further comparison) when one was asked for.
 */
final readonly class ResolvedPeriod
{
    public function __construct(
        public string $token,
        public string $start,
        public string $end,
        public ?ResolvedPeriod $comparison = null,
    ) {}

    /** The last day inside the window (`end` is exclusive), as `Y-m-d`: for a source that takes an inclusive end date. */
    public function lastDay(): string
    {
        return (new \DateTimeImmutable($this->end))->modify('-1 day')->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['token' => $this->token, 'start' => $this->start, 'end' => $this->end]
            + ($this->comparison === null ? [] : ['comparison' => $this->comparison->toArray()]);
    }
}
