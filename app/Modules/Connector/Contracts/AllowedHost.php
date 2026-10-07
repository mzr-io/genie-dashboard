<?php

namespace App\Modules\Connector\Contracts;

/**
 * A host allowlist value after validation: a lower-case host (an IPv6 literal bracketed and canonical), the scheme
 * and the resolved port (the scheme's default 443 or 80 when the Admin gave none). Nothing is trimmed or repaired:
 * a value that is not exactly a `host` or `host:port` is refused with the reason.
 */
final readonly class AllowedHost
{
    public const SCHEMES = ['http', 'https'];

    private function __construct(
        public string $host,
        public string $scheme,
        public int $port,
    ) {}

    /** @throws InvalidHost */
    public static function parse(mixed $input, mixed $scheme = null): self
    {
        $scheme ??= 'https';

        if (! is_string($scheme) || ! in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidHost(HostProblem::InvalidScheme);
        }

        if (! is_string($input) || $input === '') {
            throw new InvalidHost(HostProblem::Empty);
        }

        // Whitespace and control characters first, in any encoding: nothing is silently trimmed.
        if (preg_match('/[\x00-\x20\x7f]/', $input) === 1 || (mb_check_encoding($input, 'UTF-8') && preg_match('/[\s\p{Z}\p{Cc}\p{Cf}]/u', $input) === 1)) {
            throw new InvalidHost(HostProblem::Whitespace);
        }

        if (preg_match('/[\/\\\\?#@*%]/', $input) === 1) {
            throw new InvalidHost(HostProblem::ForbiddenCharacter);
        }

        if (preg_match('/[^\x00-\x7f]/', $input) === 1) {
            throw new InvalidHost(HostProblem::NonAscii);
        }

        [$host, $portText] = self::split($input);
        $port = self::port($portText, $scheme);

        return new self($host, $scheme, $port);
    }

    /** @return array{0: string, 1: string|null} the host (validated, normalised) and the port text */
    private static function split(string $input): array
    {
        if ($input[0] === '[') {
            if (preg_match('/\A\[([0-9a-fA-F:.]+)\](?::(.*))?\z/D', $input, $m) !== 1 || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidHost(HostProblem::Malformed);
            }

            if (BlockedAddress::blocks($m[1])) {
                throw new InvalidHost(HostProblem::BlockedAddress);
            }

            return ['['.inet_ntop((string) inet_pton($m[1])).']', $m[2] ?? null];
        }

        $colons = substr_count($input, ':');

        if ($colons > 1 || $input[0] === ':') {
            throw new InvalidHost(HostProblem::Malformed);
        }

        $host = strtolower($colons === 1 ? explode(':', $input, 2)[0] : $input);
        $portText = $colons === 1 ? explode(':', $input, 2)[1] : null;

        return [self::name($host), $portText];
    }

    private static function name(string $host): string
    {
        if (strlen($host) > 253) {
            throw new InvalidHost(HostProblem::TooLong);
        }

        $labels = explode('.', $host);

        // A last label that is a number (decimal, or 0x hexadecimal) makes the whole name a spelling of an IPv4 address.
        if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/D', end($labels)) === 1) {
            if (count($labels) !== 4 || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidHost(HostProblem::NumericAddress);
            }

            if (BlockedAddress::blocks($host)) {
                throw new InvalidHost(HostProblem::BlockedAddress);
            }

            return $host;
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                throw new InvalidHost(strlen($label) > 63 ? HostProblem::TooLong : HostProblem::InvalidLabel);
            }
        }

        return $host;
    }

    private static function port(?string $text, string $scheme): int
    {
        if ($text === null) {
            return $scheme === 'https' ? 443 : 80;
        }

        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $text) !== 1 || (int) $text > 65535) {
            throw new InvalidHost(HostProblem::InvalidPort);
        }

        return (int) $text;
    }
}
