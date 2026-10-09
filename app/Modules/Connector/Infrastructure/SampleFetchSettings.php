<?php

namespace App\Modules\Connector\Infrastructure;

use Illuminate\Contracts\Config\Repository;

/**
 * The Endpoint-test (Fetch sample) rate limits (Story 2.10): at most `membership_limit` tests per person and `workspace_limit`
 * per Workspace in any `window` seconds. Every one is `pending_input` with no default, outside the AR-57 tunable list. A limit
 * that is unset or not a positive whole number does not apply, and with no window nothing is limited: no number is invented.
 */
final class SampleFetchSettings
{
    public function __construct(private readonly Repository $config) {}

    public function window(): ?int
    {
        return $this->whole('window');
    }

    public function membershipLimit(): ?int
    {
        return $this->window() === null ? null : $this->whole('membership_limit');
    }

    public function workspaceLimit(): ?int
    {
        return $this->window() === null ? null : $this->whole('workspace_limit');
    }

    private function whole(string $name): ?int
    {
        $value = $this->config->get("dashflow.sample_fetch.{$name}.value");

        return (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) === 1)) && (int) $value > 0 ? (int) $value : null;
    }
}
