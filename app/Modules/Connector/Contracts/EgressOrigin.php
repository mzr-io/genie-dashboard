<?php

namespace App\Modules\Connector\Contracts;

/** The scheme, lower-case host and resolved port of a URL: what "the same origin" compares. */
final readonly class EgressOrigin
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    /** The origin of an absolute http or https URL, or null when it does not parse as one. */
    public static function of(string $url): ?self
    {
        if (preg_match('~\A(https?)://(?:(\[[0-9a-fA-F:.]+\])|([A-Za-z0-9.-]+))(?::([0-9]{1,5}))?(?:[/?#]|\z)~Di', $url, $m) !== 1) {
            return null;
        }

        $scheme = strtolower($m[1]);
        $host = strtolower(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
        $port = ($m[4] ?? '') === '' ? ($scheme === 'https' ? 443 : 80) : (int) $m[4];

        return new self($scheme, $host, $port);
    }

    public function equals(self $other): bool
    {
        return $this->scheme === $other->scheme && $this->host === $other->host && $this->port === $other->port;
    }
}
