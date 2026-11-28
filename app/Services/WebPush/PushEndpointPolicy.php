<?php

namespace App\Services\WebPush;

use App\Support\Networking\PublicAddress;
use InvalidArgumentException;

class PushEndpointPolicy
{
    public function __construct(private readonly PushDnsResolver $resolver) {}

    /** Syntax admission only: registration never resolves DNS or sends a request. */
    public function host(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $knownProvider = in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.notify\.windows\.com\z/D', $host) === 1
            || ($host === 'android.googleapis.com' && str_starts_with($parts['path'] ?? '', '/gcm/send/'));
        if (strlen($endpoint) > 2000 || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || ! $knownProvider
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Use a supported browser push provider HTTPS endpoint.');
        }

        return $host;
    }

    /** @return array{host:string,ip:string} */
    public function resolve(string $endpoint): array
    {
        $host = $this->host($endpoint);
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException('Push provider has no approved address.');
        }
        foreach ($addresses as $address) {
            if (! PublicAddress::accepts($address)) {
                throw new InvalidArgumentException('Push provider resolved to an unapproved address.');
            }
        }
        // Prefer IPv4 when available; no automatic re-resolution or proxy fallback.
        usort($addresses, fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return ['host' => $host, 'ip' => $addresses[0]];
    }
}
