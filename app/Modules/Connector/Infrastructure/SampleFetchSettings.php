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

    /**
     * The environment variable of the first setting a Draft's Fetch needs and that is not set to a whole number (Story 3.9), or null when all
     * are: the window and the per-membership limit. Unlike a Test endpoint, a Draft's Fetch is refused while one is unset (no number is invented).
     */
    public function missingForDraftFetch(): ?string
    {
        if ($this->whole('window') === null) {
            return 'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS';
        }

        return $this->whole('membership_limit') === null ? 'DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT' : null;
    }

    private function whole(string $name): ?int
    {
        $value = $this->config->get("dashflow.sample_fetch.{$name}.value");

        return (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) === 1)) && (int) $value > 0 ? (int) $value : null;
    }
}
