<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\HostResolver;

/** Resolves a name with the system resolver and returns every A and AAAA record. */
final class DnsHostResolver implements HostResolver
{
    /** @param  (\Closure(string, int): (array<int, array<string, mixed>>|false))|null  $lookup  `dns_get_record` unless a test replaces it */
    public function __construct(private readonly ?\Closure $lookup = null) {}

    public function resolve(string $host): array
    {
        $lookup = $this->lookup ?? static fn (string $name, int $type): array|false => @dns_get_record($name, $type);

        return self::addresses($lookup($host, DNS_A | DNS_AAAA));
    }

    /**
     * The distinct A (`ip`) and AAAA (`ipv6`) addresses of a `dns_get_record` answer; none for a failed lookup.
     *
     * @param  array<int, array<string, mixed>>|false  $records
     * @return list<string>
     */
    public static function addresses(array|false $records): array
    {
        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
