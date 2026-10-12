<?php

namespace App\Modules\Blocks\Contracts;

/**
 * The value-free structure of a Fetch as user response (Story 3.17): every path with its types and the counts, never an example, a table
 * row or any other value of the response, which stays in the Operation's encrypted, short-lived blob.
 */
final readonly class UserSampleView
{
    /**
     * @param  array<string, mixed>  $shape  `records`, `scalars`, `arrays`, `objects` and `nodes` (each node's `example` is null)
     */
    public function __construct(
        public array $shape,
        public int $status,
        public int $latencyMs,
        public string $expiresAt,
    ) {}
}
