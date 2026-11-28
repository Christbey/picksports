<?php

namespace App\Services\Billing;

use App\Services\Http\BoundedResponseStream;
use App\Services\Http\DnsResolver;
use App\Support\Networking\PublicAddress;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/** Fixed-origin, single-attempt Stripe wire seam. Never retries a possible financial send. */
class BillingProviderHttp
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function request(string $method, string $path, array $headers, ?array $form = null): array
    {
        if (! in_array($method, ['GET', 'POST'], true) || preg_match('~\A/v1/[A-Za-z0-9_/?=&%.-]{1,2048}\z~', $path) !== 1
            || ($method === 'GET' && $form !== null)) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        $url = 'https://api.stripe.com'.$path;
        $body = $form === null ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        if (strlen($body) > 16384) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        $bodyHash = hash('sha256', $body);
        $response = Http::withHeaders($headers)->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true])
            ->timeout(15)->connectTimeout(5)
            ->setHandler(function (RequestInterface $request, array $options) use ($method, $url, $bodyHash, $headers): PromiseInterface {
                if ($request->getMethod() !== $method || (string) $request->getUri() !== $url
                    || ! hash_equals($bodyHash, hash('sha256', (string) $request->getBody()))) {
                    throw new BillingProviderFailure('provider_request_mutated');
                }
                if ($request->hasHeader('Stripe-Account') || $request->hasHeader('Stripe-Context') || $request->hasHeader('X-HTTP-Method-Override')) {
                    throw new BillingProviderFailure('provider_request_mutated');
                }
                foreach ($headers as $name => $value) {
                    if ($request->getHeaderLine($name) !== $value) {
                        throw new BillingProviderFailure('provider_request_mutated');
                    }
                }
                $addresses = $this->dns->addresses('api.stripe.com');
                if ($addresses === [] || collect($addresses)->contains(fn ($ip) => ! PublicAddress::accepts($ip))) {
                    throw new BillingProviderFailure('provider_address_invalid');
                }
                $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
                $options['curl'] = [CURLOPT_RESOLVE => ['api.stripe.com:443:'.$ip]];
                $options['sink'] = new BoundedResponseStream(1048576);
                $options['proxy'] = '';
                $options['verify'] = true;
                $options['allow_redirects'] = false;

                return $this->wire($request, $options);
            })->send($method, $url, $method === 'POST' ? ['body' => $body] : []);
        if (strlen($response->body()) > 1048576 || ! $response->successful()) {
            throw new BillingProviderFailure('provider_operation_unconfirmed');
        }
        try {
            $value = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BillingProviderFailure('provider_protocol_failure');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new BillingProviderFailure('provider_protocol_failure');
        }

        return $value;
    }

    protected function wire(RequestInterface $request, array $options): PromiseInterface
    {
        return (new CurlHandler)($request, $options);
    }
}
