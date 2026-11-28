<?php

use App\Services\Billing\BillingProviderFailure;
use App\Services\Billing\BillingProviderHttp;
use App\Services\Http\DnsResolver;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

function syntheticBillingWire(array $answers, Closure $wire): array
{
    $dns = new class($answers) extends DnsResolver
    {
        public int $calls = 0;

        public function __construct(private array $answers) {}

        public function addresses(string $host): array
        {
            $this->calls++;

            return $this->answers;
        }
    };
    $http = new class($dns, $wire) extends BillingProviderHttp
    {
        public function __construct(DnsResolver $dns, private Closure $wire)
        {
            parent::__construct($dns);
        }

        protected function wire(RequestInterface $request, array $options): PromiseInterface
        {
            return ($this->wire)($request, $options);
        }
    };

    return [$http, $dns];
}

it('pins financial requests with no proxy redirect or retry and a bounded verified TLS response', function () {
    [$http, $dns] = syntheticBillingWire(['8.8.8.8'], function ($request, $options) {
        expect($request->getMethod())->toBe('POST')->and((string) $request->getUri())->toBe('https://api.stripe.com/v1/subscriptions/sub_synthetic')
            ->and((string) $request->getBody())->toBe('cancel_at_period_end=true')
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['api.stripe.com:443:8.8.8.8'])
            ->and($options['proxy'])->toBe('')->and($options['verify'])->toBeTrue()->and($options['allow_redirects'])->toBeFalse();

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"id":"sub_synthetic"}'));
    });
    expect($http->request('POST', '/v1/subscriptions/sub_synthetic', ['Idempotency-Key' => 'synthetic'], ['cancel_at_period_end' => 'true']))
        ->toBe(['id' => 'sub_synthetic'])->and($dns->calls)->toBe(1);
});

it('refuses rewritten financial method URI body or idempotency header before DNS or wire', function (string $mutation) {
    Http::globalRequestMiddleware(function (RequestInterface $request) use ($mutation) {
        return match ($mutation) {
            'method' => $request->withMethod('DELETE'),
            'uri' => $request->withUri(new Uri('https://api.stripe.com/v1/customers/cus_other')),
            'body' => $request->withBody(Utils::streamFor('cancel_at_period_end=false')),
            'key' => $request->withHeader('Idempotency-Key', 'different'),
            'account' => $request->withHeader('Stripe-Account', 'acct_other'),
            'context' => $request->withHeader('Stripe-Context', 'acct_other'),
            'override' => $request->withHeader('X-HTTP-Method-Override', 'DELETE'),
        };
    });
    [$http, $dns] = syntheticBillingWire(['8.8.8.8'], fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $http->request('POST', '/v1/subscriptions/sub_synthetic', ['Idempotency-Key' => 'synthetic'], ['cancel_at_period_end' => 'true']))
        ->toThrow(BillingProviderFailure::class)->and($dns->calls)->toBe(0);
})->with(['method', 'uri', 'body', 'key', 'account', 'context', 'override']);

it('refuses a financial destination with any private or transitional DNS answer', function (array $answers) {
    [$http, $dns] = syntheticBillingWire($answers, fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $http->request('GET', '/v1/account', []))->toThrow(BillingProviderFailure::class)
        ->and($dns->calls)->toBe(1);
})->with([[['127.0.0.1']], [['8.8.8.8', '10.1.2.3']], [['::ffff:8.8.8.8']], [['64:ff9b::808:808']]]);
