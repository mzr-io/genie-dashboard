<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\Cidr;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The deployment's egress settings (`dashflow.egress`, every one `pending_input` with no default): the deployment's own
 * CIDRs and the SSRF alert threshold and window. Unset means no deployment CIDR beyond the built-in ranges and no alert.
 */
final class EgressSettings
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<Cidr>
     *
     * @throws InvalidArgumentException when an entry is not a valid CIDR (a bad setting fails closed, never silently)
     */
    public function deploymentCidrs(): array
    {
        $value = $this->config->get('dashflow.egress.deployment_cidrs.value');

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_map(
            fn (string $entry): Cidr => Cidr::parse(trim($entry)) ?? throw new InvalidArgumentException('DASHFLOW_EGRESS_DEPLOYMENT_CIDRS holds an entry that is not a valid CIDR.'),
            explode(',', $value),
        );
    }

    /** The blocks per Workspace within the window that raise the alert; null (alert off) unless both settings are positive whole numbers. */
    public function alert(): ?AlertRate
    {
        $threshold = $this->whole('dashflow.egress.alert_threshold.value');
        $window = $this->whole('dashflow.egress.alert_window.value');

        return $threshold === null || $window === null ? null : new AlertRate($threshold, $window);
    }

    private function whole(string $key): ?int
    {
        $value = $this->config->get($key);

        return (is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $value) === 1)) && (int) $value > 0 ? (int) $value : null;
    }
}
