<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\HostResolver;

/** A resolver that answers from a table and records every lookup (a name must be resolved once, never twice). */
final class FakeResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /**
     * Per host, one answer for each successive lookup (the last repeats): how a rebinding name behaves.
     *
     * @var array<string, list<list<string>>>
     */
    public array $sequences = [];

    /** @param  array<string, list<string>>  $answers */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        if (isset($this->sequences[$host])) {
            return $this->sequences[$host][min(count($this->lookups) - 1, count($this->sequences[$host]) - 1)];
        }

        return $this->answers[$host] ?? [];
    }
}
