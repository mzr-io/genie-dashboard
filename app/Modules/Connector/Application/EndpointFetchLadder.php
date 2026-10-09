<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FailureClass;
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
    /** cURL errors that prove a request was not sent: the name did not resolve (6), the connection was refused (7) or the TLS handshake never completed (35). */
    public const PRE_SEND_ERRORS = [6, 7, 35];

    /**
     * What an HTTP answer that is neither a 2xx nor a 304 means (Story 2.17): a 429, or a 503 with a valid `Retry-After`, is
     * `throttled`; a 408 or another 5xx is `transient` (a POST's 408 or 5xx is `ambiguous`: the work may have been done); any other status is
     * `configuration`.
     */
    public function classifyStatus(int $status, ?int $retryAfterSeconds, bool $post): FailureClass
    {
        return match (true) {
            $status === 429, $status === 503 && $retryAfterSeconds !== null => FailureClass::Throttled,
            $status === 408 => $post ? FailureClass::Ambiguous : FailureClass::Transient,
            $status >= 500 => $post ? FailureClass::Ambiguous : FailureClass::Transient,
            default => FailureClass::Configuration,
        };
    }

    /**
     * @param  int  $began  `hrtime(true)` taken when the request was started: the time a failure took when it carries no latency of its own
     * @param  array<string, mixed>  $context  what the operator log lines carry (identifiers only)
     * @param  string  $event  the log event prefix
     * @param  bool  $post  the call is a POST: a failure after the request may have been sent is `ambiguous` (Story 2.17)
     */
    public function classify(Throwable $e, int $began, array $context = [], string $event = 'connector.sample_fetch', bool $post = false): FetchFailure
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

        $failure = $this->cause($e, $began, $context, $event, $post);

        return new FetchFailure(
            $failure->code, $failure->reason, $failure->latencyMs ?? $latencyMs, $failure->status, $failure->bytes, $failure->limitBytes, $page, $pages, $failure->class,
        );
    }

    /**
     * The user code and reason of a failure that is not the Endpoint's own to explain (the transport, the guard, a limit, the credentials).
     *
     * @param  array<string, mixed>  $context
     */
    private function cause(Throwable $e, int $began, array $context, string $event, bool $post): FetchFailure
    {
        if ($e instanceof InvalidLimitSetting) {
            Log::error($event.'.limit_setting_invalid', [...$context, 'setting' => $e->setting]);

            return new FetchFailure(ConnectionTestCode::FetchFailed, 'misconfigured', class: FailureClass::Configuration);
        }

        if ($e instanceof AuthFailed) {
            return new FetchFailure(ConnectionTestCode::AuthFailed, $e->code()->value.':'.$e->reason, $this->elapsed($began), class: FailureClass::Configuration);
        }

        if ($e instanceof TokenRequestFailed) {
            return new FetchFailure(ConnectionTestCode::FetchFailed, 'oauth_'.$e->reason, $this->elapsed($began), class: FailureClass::Configuration);
        }

        if ($e instanceof PageLimitExceeded) {
            // The run would need a page beyond the cap: nothing is kept and nothing is truncated.
            return new FetchFailure(ConnectionTestCode::TooManyPages, $e->code()->value, $this->elapsed($began), class: FailureClass::Data);
        }

        if ($e instanceof PaginationFailed) {
            return new FetchFailure(ConnectionTestCode::FetchFailed, 'pagination_'.$e->reason, $this->elapsed($began), class: FailureClass::Data);
        }

        if ($e instanceof NotJsonResponse) {
            return new FetchFailure(ConnectionTestCode::NotJson, $e->code()->value.':'.$e->reason, class: FailureClass::Data);
        }

        if ($e instanceof ResponseLimitExceeded) {
            // The limit was passed while reading: nothing is kept, and no truncated sample exists to show.
            return new FetchFailure(ConnectionTestCode::ResponseTooLarge, $e->code()->value, $this->elapsed($began), $e->status, $e->bytesRead, $e->limit, class: FailureClass::Data);
        }

        if ($e instanceof SsrfBlocked) {
            return new FetchFailure(ConnectionTestCode::forEgress($e->reason), $e->reason->value, class: FailureClass::Configuration);
        }

        if ($e instanceof EgressTransportFailed) {
            // The TLS handshake that never completed (35) proves nothing was sent: it is retried like a connect error; other TLS errors are the Admin's.
            $tls = in_array($e->errno, RunConnectionTest::TLS_ERRORS, true);

            return new FetchFailure(ConnectionTestCode::FetchFailed, match (true) {
                $e->errno === 28 => 'timeout',
                $tls => 'tls',
                default => 'transport',
            }, $this->elapsed($began), class: match (true) {
                $tls && $e->errno !== 35 => FailureClass::Configuration,
                // A POST is only repeated when the request cannot have been sent: DNS (6), connect (7) or the TLS handshake (35) failed.
                $post && ! in_array($e->errno, self::PRE_SEND_ERRORS, true) => FailureClass::Ambiguous,
                default => FailureClass::Transient,
            });
        }

        return match (true) {
            $e instanceof KeyringUnavailable => new FetchFailure(ConnectionTestCode::FetchFailed, 'keyring_unavailable', class: FailureClass::Configuration),
            $e instanceof KeyringMismatch => new FetchFailure(ConnectionTestCode::FetchFailed, 'keyring_mismatch', class: FailureClass::Configuration),
            $e instanceof SecretMissing => new FetchFailure(ConnectionTestCode::FetchFailed, 'secret_missing', class: FailureClass::Configuration),
            $e instanceof SecretRefused => new FetchFailure(ConnectionTestCode::FetchFailed, 'secret_refused', class: FailureClass::Configuration),
            default => $this->unknown($e, $context, $event),
        };
    }

    /** @param  array<string, mixed>  $context */
    private function unknown(Throwable $e, array $context, string $event): FetchFailure
    {
        Log::error($event.'.error', [...$context, 'exception' => $e::class]);

        // An exception nobody expected is never retried: it is a bug or a setup problem, and the operator log line names its class.
        return new FetchFailure(ConnectionTestCode::FetchFailed, 'error', class: FailureClass::Configuration);
    }

    private function elapsed(int $began): int
    {
        return (int) round((hrtime(true) - $began) / 1_000_000);
    }
}
