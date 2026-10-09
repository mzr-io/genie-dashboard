<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Infrastructure\EgressSettings;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\TenantKey;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\MetricName;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every refused outbound request ends here: one `connector.egress.blocked` security event (reason, host, port and the
 * request ID the audit kernel adds; never a resolved address) and one count towards the SSRF alert.
 *
 * The alert is rate based per Workspace over a fixed window of `dashflow.egress.alert_window` seconds: the metric
 * `dashflow.connector.ssrf_blocked` fires for each block beyond `dashflow.egress.alert_threshold` in that window. With
 * either setting unset nothing fires. Its labels are the Workspace ID and the reason only.
 */
final class RecordEgressBlock implements EgressBlockLog
{
    private const HOST = '/\A[a-z0-9.:\[\]-]{1,255}\z/D';

    public function __construct(
        private readonly Audit $audit,
        private readonly EgressSettings $settings,
        private readonly Cache $cache,
        private readonly MetricEmitter $metrics,
    ) {}

    public function record(string $workspaceId, EgressReason $reason, ?string $host, ?int $port): void
    {
        $host = $host !== null && preg_match(self::HOST, $host) === 1 ? $host : null;

        try {
            $this->audit->recordSecurityEvent(
                AuditAction::ConnectorEgressBlocked,
                ['reason' => $reason->value, 'host' => $host, 'port' => $port],
                $workspaceId,
                subject: 'workspace:'.$workspaceId,
            );
        } catch (Throwable $e) {
            // The denial must still reach the caller as connector.ssrf_blocked. Class only: no address, no message.
            Log::error('connector.egress.audit_failed', ['workspace_id' => $workspaceId, 'reason' => $reason->value, 'exception' => $e::class]);
        }

        // A name that does not resolve is audited but is not a blocked address: it does not count towards the alert.
        if ($reason !== EgressReason::Unresolvable) {
            $this->alert($workspaceId, $reason);
        }
    }

    private function alert(string $workspaceId, EgressReason $reason): void
    {
        try {
            $rate = $this->settings->alert();

            if ($rate === null) {
                return;
            }

            $key = TenantKey::cache($workspaceId, 'connector:egress-blocks:'.intdiv(time(), $rate->windowSeconds));
            $this->cache->add($key, 0, $rate->windowSeconds);
            $count = (int) $this->cache->increment($key);

            if ($count > $rate->threshold) {
                $this->metrics->increment(MetricName::make('connector', 'ssrf_blocked'), ['workspace_id' => $workspaceId, 'reason' => $reason->value]);
                Log::warning('connector.ssrf_blocked.alert', ['workspace_id' => $workspaceId, 'reason' => $reason->value, 'blocks' => $count]);
            }
        } catch (Throwable $e) {
            // A counting failure must not turn a denial into an error.
            Log::error('connector.ssrf_blocked.alert_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }
    }
}
