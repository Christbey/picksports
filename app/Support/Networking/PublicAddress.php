<?php

namespace App\Support\Networking;

use Symfony\Component\HttpFoundation\IpUtils;

final class PublicAddress
{
    public static function accepts(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        if (str_contains($address, ':')) {
            // Exclude mapped, NAT64, multicast and local addresses by admitting
            // global-unicast only, then reject IPv4 transition tunnels too.
            return IpUtils::checkIp($address, '2000::/3')
                && ! IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16', '3ffe::/16', '3fff::/20']);
        }

        return ! IpUtils::checkIp($address, [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        ]);
    }
}
