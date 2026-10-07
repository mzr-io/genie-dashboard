<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\DataSourceCeilings;
use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Contracts\Config\Repository;

/**
 * The settings a Data Source is checked against (Story 2.3). `require_https` is the Workspace setting OR the deployment
 * setting `dashflow.tunables.guards.require_https` (off by default): either one on refuses an `http://` Base URL. The
 * ceilings are the `pending_input` platform settings; one that is unset is not checked (no number is invented).
 */
final class DataSourceSettings
{
    public function __construct(
        private readonly Repository $config,
        private readonly WorkspaceSettings $workspace,
    ) {}

    /** Whether an `http://` Base URL is refused in the current Workspace transaction. */
    public function requireHttps(): bool
    {
        return $this->deploymentRequiresHttps() || $this->workspace->requireHttps();
    }

    public function deploymentRequiresHttps(): bool
    {
        return filter_var($this->config->get('dashflow.tunables.guards.require_https.value'), FILTER_VALIDATE_BOOLEAN);
    }

    public function ceilings(): DataSourceCeilings
    {
        return new DataSourceCeilings(
            $this->whole('dashflow.tunables.guards.platform_timeout_ceiling.value'),
            $this->whole('dashflow.tunables.guards.max_bytes.value'),
            $this->whole('dashflow.tunables.guards.max_pages.value'),
        );
    }

    private function whole(string $key): ?int
    {
        $value = $this->config->get($key);

        return (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', trim($value)) === 1)) && (int) $value > 0 ? (int) $value : null;
    }
}
