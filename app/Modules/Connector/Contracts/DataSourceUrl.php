<?php

namespace App\Modules\Connector\Contracts;

/**
 * A Data Source Base URL after validation: `http` or `https` only, no userinfo, query or fragment, a host that passes
 * Story 2.1's {@see AllowedHost} rules and a path of plain URL characters. Nothing is trimmed or repaired. The stored
 * `scheme`, `host` and `port` (resolved: the scheme's default when omitted) are derived from it, and `baseUrl` is its
 * normal form (lower-case scheme and host, the port only when it is not the scheme's default). Parsing never resolves
 * a name or contacts a host.
 */
final readonly class DataSourceUrl
{
    public const MAX_LENGTH = 2048;

    private function __construct(
        public string $baseUrl,
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    /** @throws InvalidDataSourceUrl */
    public static function parse(mixed $input): self
    {
        if (! is_string($input) || $input === '') {
            throw new InvalidDataSourceUrl(UrlProblem::Empty);
        }

        if (strlen($input) > self::MAX_LENGTH) {
            throw new InvalidDataSourceUrl(UrlProblem::TooLong);
        }

        if (preg_match('/[\x00-\x20\x7f]/', $input) === 1 || (mb_check_encoding($input, 'UTF-8') && preg_match('/[\s\p{Z}\p{Cc}\p{Cf}]/u', $input) === 1)) {
            throw new InvalidDataSourceUrl(UrlProblem::Whitespace);
        }

        if (str_contains($input, '\\') || preg_match('/[^\x00-\x7f]/', $input) === 1 || preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*)://(.*)\z~Ds', $input, $m) !== 1) {
            throw new InvalidDataSourceUrl(UrlProblem::Malformed);
        }

        $scheme = strtolower($m[1]);

        if (! in_array($scheme, AllowedHost::SCHEMES, true)) {
            throw new InvalidDataSourceUrl(UrlProblem::Scheme);
        }

        $rest = $m[2];
        $end = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $end);
        $tail = substr($rest, $end);

        if (str_contains($authority, '@')) {
            throw new InvalidDataSourceUrl(UrlProblem::Userinfo);
        }

        if (str_contains($tail, '?')) {
            throw new InvalidDataSourceUrl(UrlProblem::Query);
        }

        if (str_contains($tail, '#')) {
            throw new InvalidDataSourceUrl(UrlProblem::Fragment);
        }

        if ($tail !== '' && (preg_match('~\A/[A-Za-z0-9\-._\~!$&\'()*+,;=:@%/]*\z~D', $tail) !== 1 || preg_match('/%(?![0-9A-Fa-f]{2})/', $tail) === 1)) {
            throw new InvalidDataSourceUrl(UrlProblem::Malformed);
        }

        // A `.` or `..` segment (also encoded) or an encoded separator could leave the prefix when a path is joined later.
        if ($tail !== '' && (preg_match('/%(?:2f|5c)/i', $tail) === 1 || array_filter(explode('/', $tail), fn (string $segment): bool => in_array(rawurldecode($segment), ['.', '..'], true)) !== [])) {
            throw new InvalidDataSourceUrl(UrlProblem::Malformed);
        }

        try {
            $host = AllowedHost::parse($authority, $scheme);
        } catch (InvalidHost $e) {
            throw new InvalidDataSourceUrl(UrlProblem::InvalidHost, $e->problem);
        }

        $default = $scheme === 'https' ? 443 : 80;
        $port = $host->port === $default ? '' : ':'.$host->port;

        return new self($scheme.'://'.$host->host.$port.$tail, $scheme, $host->host, $host->port);
    }
}
