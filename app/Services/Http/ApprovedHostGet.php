<?php

namespace App\Services\Http;

use App\Support\Networking\PublicAddress;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class ApprovedHostGet
{
    public function __construct(private readonly DnsResolver $resolver) {}

    public function normalize(string $url, array $hosts, bool $upgradeHttp = false): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url) || ! is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array($host, $hosts, true) || filter_var($host, FILTER_VALIDATE_IP) !== false
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || ($scheme !== 'https' && ! ($scheme === 'http' && $upgradeHttp))) {
            throw new InvalidArgumentException('Outbound URL is not an approved HTTPS origin.');
        }

        return preg_replace('~\Ahttps?://[^/?#]+~i', 'https://'.$host, $url);
    }

    public function get(string $url, array $hosts, int $maximumBytes, bool $upgradeHttp = false, array $headers = [], bool $followRedirects = true, ?\Closure $remainingBudget = null): Response
    {
        if ($maximumBytes < 1 || $maximumBytes > 50 * 1024 * 1024) {
            throw new InvalidArgumentException('Invalid response byte budget.');
        }
        // Research callers own redirects and retain one 15-second attempt per hop.
        $attemptSeconds = $followRedirects ? 20 : 15;
        $deadline = microtime(true) + ($followRedirects ? 40 : 15);
        for ($hop = 0; $hop < 4; $hop++) {
            $remaining = min($deadline - microtime(true), $remainingBudget === null ? INF : $remainingBudget());
            if ($remaining <= 0) {
                throw new RuntimeException('Approved request aggregate deadline expired.');
            }
            $url = $this->normalize($url, $hosts, $upgradeHttp);
            $response = Http::withHeaders($headers)->connectTimeout(min(10, $remaining))->timeout(min($attemptSeconds, $remaining))->retry($followRedirects ? 2 : 1, 250, throw: false)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'protocols' => ['https']])
                // Laravel's stub handler intercepts before this actual wire seam.
                // Faked requests therefore never resolve DNS or reach a socket.
                ->setHandler(function (RequestInterface $request, array $options) use ($maximumBytes, $deadline, $hosts, $url, $attemptSeconds, $remainingBudget): PromiseInterface {
                    if ($request->getMethod() !== 'GET') {
                        throw new InvalidArgumentException('Approved transport permits GET only.');
                    }
                    $this->normalize((string) $request->getUri(), $hosts);
                    $flags = UriNormalizer::CAPITALIZE_PERCENT_ENCODING | UriNormalizer::DECODE_UNRESERVED_CHARACTERS | UriNormalizer::CONVERT_EMPTY_PATH;
                    if ((string) UriNormalizer::normalize($request->getUri(), $flags) !== (string) UriNormalizer::normalize(new Uri($url), $flags)) {
                        throw new InvalidArgumentException('Approved request URI was changed after admission.');
                    }
                    $host = strtolower($request->getUri()->getHost());
                    if ($remainingBudget !== null) {
                        $remainingBudget();
                    }
                    $addresses = $this->resolver->addresses($host);
                    if ($addresses === [] || collect($addresses)->contains(fn ($address) => ! PublicAddress::accepts($address))) {
                        throw new InvalidArgumentException('Approved host resolved to an unapproved address.');
                    }
                    usort($addresses, fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
                    $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
                    $remaining = min($deadline - microtime(true), $remainingBudget === null ? INF : $remainingBudget());
                    if ($remaining <= 0) {
                        throw new RuntimeException('Approved request time budget expired.');
                    }
                    $options['timeout'] = min($attemptSeconds, $remaining);
                    $options['connect_timeout'] = min(10, $remaining);
                    $options['curl'] = [CURLOPT_RESOLVE => [$host.':443:'.$ip]];
                    $options['sink'] = new BoundedResponseStream($maximumBytes);
                    $options['proxy'] = '';
                    $options['verify'] = true;
                    $options['protocols'] = ['https'];

                    return $this->wire($request, $options);
                })->get($url);
            // Fakes and alternative response producers still obey the byte budget.
            if (strlen($response->body()) > $maximumBytes) {
                throw new RuntimeException('Approved response exceeded its byte limit.');
            }
            if (! $followRedirects || ! $response->redirect()) {
                return $response;
            }
            if ($hop === 3) {
                throw new RuntimeException('Approved response redirect limit reached.');
            }
            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                throw new RuntimeException('Approved response redirect is missing a destination.');
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }
        throw new RuntimeException('Approved response redirect limit reached.');
    }

    /** Injectable final wire boundary: tests return synthetic PSR responses without networking. */
    protected function wire(RequestInterface $request, array $options): PromiseInterface
    {
        return (new CurlHandler)($request, $options);
    }
}
