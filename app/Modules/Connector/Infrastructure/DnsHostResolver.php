<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\HostResolver;

/** Resolves a name with the system resolver and returns every A and AAAA record. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        return self::addresses(@dns_get_record($host, DNS_A | DNS_AAAA));
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
