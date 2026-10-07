<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressGuard;
use App\Modules\Connector\Contracts\EgressOrigin;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EgressVerdict;
use App\Modules\Connector\Contracts\ReservedHeaders;
use App\Modules\Connector\Contracts\SsrfBlocked;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The curl-only egress transport (Story 2.2).
 *
 * Each hop: the EgressGuard decides the URL, then curl connects to exactly the address the guard checked
 * (`CURLOPT_RESOLVE` for that host and port, so the name is never resolved again and a rebinding answer is never used).
 * Only http and https are accepted, also for redirects. Proxy variables (`HTTP_PROXY`, `http_proxy`, `ALL_PROXY` and the
 * rest) are ignored: the proxy option is set empty and `NO_PROXY` is `*`, so curl never reads the environment (httpoxy).
 * curl follows no redirect: the transport reads each 3xx itself. A target on another origin, or an https-to-http
 * downgrade, is refused and audited as a security event; nothing is sent on a refused hop, so credentials never reach
 * another origin. A same-origin target keeps its credentials and goes through the whole guard again.
 */
final class CurlEgressTransport implements EgressTransport
{
    /** The redirect chain is bounded; a longer one is refused. */
    public const MAX_REDIRECTS = 5;

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly EgressGuard $guard,
        private readonly CurlClient $curl,
        private readonly EgressBlockLog $blocks,
        private readonly Repository $config,
    ) {}

    public function send(string $workspaceId, EgressRequest $request): EgressResponse
    {
        $url = $request->url;
        $method = strtoupper($request->method);
        $body = $request->body;

        for ($hop = 0; ; $hop++) {
            $verdict = $this->guard->decide($workspaceId, $url);

            if (! $verdict->allowed || $verdict->pinnedIp === null) {
                throw new SsrfBlocked($verdict->reason ?? EgressReason::HostNotAllowlisted, $verdict);
            }

            $result = $this->curl->execute($this->options($verdict, $url, $method, $request, $body));

            $location = in_array($result->status, self::REDIRECTS, true) ? ($result->headers['location'][0] ?? null) : null;

            if ($location === null) {
                return new EgressResponse($result->status, $result->headers, $result->body, $url, $hop);
            }

            $current = EgressOrigin::of($url);
            $target = $this->target($url, $location);
            $next = $target === null ? null : EgressOrigin::of($target);

            // Only a target that is provably the same origin (same scheme, host and port) may be followed: anything else,
            // including a downgrade or a target that does not parse, is refused and nothing is sent to it.
            if ($hop >= self::MAX_REDIRECTS || $target === null || $next === null || $current === null || ! $next->equals($current)) {
                $this->blocks->record($workspaceId, EgressReason::RedirectRefused, $next?->host, $next?->port);

                throw new SsrfBlocked(EgressReason::RedirectRefused);
            }

            if ($result->status === 303 || (in_array($result->status, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
                $body = null;
            }

            $url = $target;
        }
    }

    /**
     * @return array<int, mixed>
     */
    public function options(EgressVerdict $verdict, string $url, string $method, EgressRequest $request, ?string $body): array
    {
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Only GET and POST requests can be sent.');
        }

        $ip = (string) $verdict->pinnedIp;
        $host = trim($verdict->host, '[]');
        $headers = [];

        foreach ([...$request->headers, ...$request->credentials] as $name => $value) {
            if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', (string) $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException('A request header holds a character that is not allowed.');
            }

            // These belong to the transport: a caller's Host would reach another virtual host on the pinned address.
            if (ReservedHeaders::transport((string) $name)) {
                throw new InvalidArgumentException('A request header is reserved for the transport.');
            }

            $headers[] = $name.': '.$value;
        }

        $options = [
            CURLOPT_URL => $url,
            // The pin: this host and port connect to the checked address and to nothing else.
            CURLOPT_RESOLVE => [$host.':'.$verdict->port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            // httpoxy: never a proxy, whatever the environment says.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_UNRESTRICTED_AUTH => false,
        ];

        // http and https only, for the request and for any redirect (the string form where this cURL has it).
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        foreach ([CURLOPT_CONNECTTIMEOUT => 'connect_timeout', CURLOPT_TIMEOUT => 'total_timeout'] as $option => $name) {
            $seconds = $this->config->get("dashflow.tunables.timeouts.{$name}.value");

            if (is_numeric($seconds) && (int) $seconds > 0) {
                $options[$option] = (int) $seconds;
            }
        }

        // The Data Source's own timeout may only shorten the total timeout.
        if ($request->timeoutSeconds !== null && $request->timeoutSeconds > 0) {
            $options[CURLOPT_TIMEOUT] = min($request->timeoutSeconds, $options[CURLOPT_TIMEOUT] ?? $request->timeoutSeconds);
        }

        return $options;
    }

    /** The absolute URL a Location header points to, relative to the current URL; null when it cannot be resolved. */
    private function target(string $base, string $location): ?string
    {
        $location = (string) preg_replace('/#.*\z/s', '', $location);

        if ($location === '') {
            return null;
        }

        if (preg_match('~\A[A-Za-z][A-Za-z0-9+.-]*:~', $location) === 1) {
            return $location;
        }

        if (preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*:)//([^/?#]*)([^?#]*)~', $base, $b) !== 1) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $b[1].$location;
        }

        if ($location[0] === '/') {
            return $b[1].'//'.$b[2].$location;
        }

        if ($location[0] === '?') {
            return $b[1].'//'.$b[2].$b[3].$location;
        }

        $directory = substr($b[3], 0, (int) strrpos($b[3], '/'));

        return $b[1].'//'.$b[2].$directory.'/'.$location;
    }
}
