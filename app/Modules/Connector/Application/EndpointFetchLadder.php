<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\KeyringMismatch;
use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\PageFailed;
use App\Modules\Connector\Contracts\PageLimitExceeded;
use App\Modules\Connector\Contracts\PaginationFailed;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestFailed;
use App\Platform\Json\InvalidLimitSetting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The error ladder of an Endpoint fetch (Stories 2.5 to 2.11), shared by the Endpoint test ({@see RunSampleFetch}) and the scheduled
 * fetch ({@see FetchEndpoint}): what a guard, transport, credential, limit or JSON failure means to an Admin. It maps one
 * exception to a {@see FetchFailure} (a user code, a reason and the numbers the failure knew); an exception it does not know is
 * `fetch-failed` with the reason `error` and an operator log line naming only its class. A paged failure is judged by its cause, with
 * the page it stopped at.
 */
final class EndpointFetchLadder
{
    /**
     * @param  int  $began  `hrtime(true)` taken when the request was started: the time a failure took when it carries no latency of its own
     * @param  array<string, mixed>  $context  what the operator log lines carry (identifiers only)
     * @param  string  $event  the log event prefix
     */
    public function classify(Throwable $e, int $began, array $context = [], string $event = 'connector.sample_fetch'): FetchFailure
    {
        $page = $pages = $latencyMs = null;

        if ($e instanceof PageFailed) {
            // One page of a paged run failed: the whole fetch failed at that page. What it raised is judged as an unpaged call's would be.
            $page = $e->page;
            $pages = $e->pages;
            // The time the run took up to the failure; a limit cause (response-too-large) also brings its sizes through the ladder below.
            $latencyMs = $this->elapsed($began);
            $e = $e->cause;
        }

        $failure = $this->cause($e, $began, $context, $event);

        return new FetchFailure(
            $failure->code, $failure->reason, $failure->latencyMs ?? $latencyMs, $failure->status, $failure->bytes, $failure->limitBytes, $page, $pages,
        );
    }

    /**
     * The user code and reason of a failure that is not the Endpoint's own to explain (the transport, the guard, a limit, the credentials).
     *
     * @param  array<string, mixed>  $context
     */
    private function cause(Throwable $e, int $began, array $context, string $event): FetchFailure
    {
        if ($e instanceof InvalidLimitSetting) {
            Log::error($event.'.limit_setting_invalid', [...$context, 'setting' => $e->setting]);

            return new FetchFailure(ConnectionTestCode::FetchFailed, 'misconfigured');
        }

        if ($e instanceof AuthFailed) {
            return new FetchFailure(ConnectionTestCode::AuthFailed, $e->code()->value.':'.$e->reason, $this->elapsed($began));
        }

        if ($e instanceof TokenRequestFailed) {
            return new FetchFailure(ConnectionTestCode::FetchFailed, 'oauth_'.$e->reason, $this->elapsed($began));
        }

        if ($e instanceof PageLimitExceeded) {
            // The run would need a page beyond the cap: nothing is kept and nothing is truncated.
            return new FetchFailure(ConnectionTestCode::TooManyPages, $e->code()->value, $this->elapsed($began));
        }

        if ($e instanceof PaginationFailed) {
            return new FetchFailure(ConnectionTestCode::FetchFailed, 'pagination_'.$e->reason, $this->elapsed($began));
        }

        if ($e instanceof NotJsonResponse) {
            return new FetchFailure(ConnectionTestCode::NotJson, $e->code()->value.':'.$e->reason);
        }

        if ($e instanceof ResponseLimitExceeded) {
            // The limit was passed while reading: nothing is kept, and no truncated sample exists to show.
            return new FetchFailure(ConnectionTestCode::ResponseTooLarge, $e->code()->value, $this->elapsed($began), $e->status, $e->bytesRead, $e->limit);
        }

        if ($e instanceof SsrfBlocked) {
            return new FetchFailure(ConnectionTestCode::forEgress($e->reason), $e->reason->value);
        }

        if ($e instanceof EgressTransportFailed) {
            return new FetchFailure(ConnectionTestCode::FetchFailed, match (true) {
                $e->errno === 28 => 'timeout',
                in_array($e->errno, RunConnectionTest::TLS_ERRORS, true) => 'tls',
                default => 'transport',
            }, $this->elapsed($began));
        }

        return match (true) {
            $e instanceof KeyringUnavailable => new FetchFailure(ConnectionTestCode::FetchFailed, 'keyring_unavailable'),
            $e instanceof KeyringMismatch => new FetchFailure(ConnectionTestCode::FetchFailed, 'keyring_mismatch'),
            $e instanceof SecretMissing => new FetchFailure(ConnectionTestCode::FetchFailed, 'secret_missing'),
            $e instanceof SecretRefused => new FetchFailure(ConnectionTestCode::FetchFailed, 'secret_refused'),
            default => $this->unknown($e, $context, $event),
        };
    }

    /** @param  array<string, mixed>  $context */
    private function unknown(Throwable $e, array $context, string $event): FetchFailure
    {
        Log::error($event.'.error', [...$context, 'exception' => $e::class]);

        return new FetchFailure(ConnectionTestCode::FetchFailed, 'error');
    }

    private function elapsed(int $began): int
    {
        return (int) round((hrtime(true) - $began) / 1_000_000);
    }
}
