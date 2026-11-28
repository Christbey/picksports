<?php

namespace App\Services\Http;

use RuntimeException;

class DnsResolver
{
    /** @return list<string> */
    public function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new RuntimeException('Approved host resolution failed.');
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
