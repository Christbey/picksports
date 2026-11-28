<?php

use App\Services\Http\ApprovedHostGet;
use App\Services\Http\BoundedResponseStream;
use App\Services\Http\DnsResolver;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

function syntheticApprovedTransport(array $addresses, Closure $wire): array
{
    $dns = new class($addresses) extends DnsResolver
    {
        public int $calls = 0;

        public function __construct(private array $answers) {}

        public function addresses(string $host): array
        {
            $this->calls++;

            return $this->answers;
        }
    };
    $transport = new class($dns, $wire) extends ApprovedHostGet
    {
        public function __construct(DnsResolver $dns, private Closure $response)
        {
            parent::__construct($dns);
        }

        protected function wire(RequestInterface $request, array $options): PromiseInterface
        {
            return ($this->response)($request, $options);
        }
    };

    return [$transport, $dns];
}

it('rejects unapproved URL syntax before fake dispatch DNS or any wire action', function (string $url) {
    Http::preventStrayRequests();
    [$transport,$dns] = syntheticApprovedTransport(['8.8.8.8'], fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $transport->get($url, ['assets.example'], 100))->toThrow(InvalidArgumentException::class)
        ->and($dns->calls)->toBe(0);
    Http::assertNothingSent();
})->with(['http://assets.example/image.png', 'https://evil.example/image.png', 'https://assets.example.evil/image.png',
    'https://assets.example:443/image.png', 'https://user:secret@assets.example/image.png', 'https://127.0.0.1/image.png',
    'file:///etc/passwd', 'ftp://assets.example/image.png', 'https://assets.example/image.png#fragment']);

it('rejects every nonpublic or mixed DNS answer before reaching the final wire seam', function (array $answers) {
    [$transport,$dns] = syntheticApprovedTransport($answers, fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $transport->get('https://assets.example/image.png', ['assets.example'], 100))->toThrow(InvalidArgumentException::class);
    expect($dns->calls)->toBeGreaterThan(0);
})->with([[[]], [['127.0.0.1']], [['10.1.2.3']], [['169.254.169.254']], [['100.64.0.1']], [['224.0.0.1']],
    [['::1']], [['::ffff:8.8.8.8']], [['64:ff9b::808:808']], [['2002:0808:0808::1']], [['8.8.8.8', '192.168.0.1']]]);

it('pins validated DNS with strict TLS no proxy no redirects and a bounded decoded sink', function () {
    [$transport,$dns] = syntheticApprovedTransport(['2001:4860:4860::8888', '8.8.8.8'], function ($request, $options) {
        expect((string) $request->getUri())->toBe('https://assets.example/Opaque/Image.png?Token=Case')
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['assets.example:443:8.8.8.8'])
            ->and($options['verify'])->toBeTrue()->and($options['proxy'])->toBe('')
            ->and($options['protocols'])->toBe(['https'])->and($options['allow_redirects'])->toBeFalse()
            ->and($options['timeout'])->toBeLessThanOrEqual(20);
        // Build/release the real cURL configuration without curl_exec: no DNS/socket.
        $factory = new CurlFactory(0);
        $easy = $factory->create($request, $options);
        $factory->release($easy);
        $options['sink']->write('decoded-body');
        $options['sink']->rewind();

        return Create::promiseFor(new Response(200, ['Content-Type' => 'text/plain'], $options['sink']));
    });
    expect($transport->get('https://ASSETS.EXAMPLE/Opaque/Image.png?Token=Case', ['assets.example'], 20)->body())->toBe('decoded-body')
        ->and($dns->calls)->toBe(1);
});

it('aborts decoded chunks before the sink can grow beyond its byte cap', function () {
    $stream = new BoundedResponseStream(10);
    $stream->write('12345678');
    expect(fn () => $stream->write('901'))->toThrow(RuntimeException::class, 'byte limit')
        ->and($stream->getSize())->toBe(8);
});

it('validates redirect destinations before requesting them and upgrades admitted historical ESPN HTTP refs without HTTP traffic', function () {
    Http::preventStrayRequests();
    Http::fake(['https://sports.core.api.espn.com/ref' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
    [$transport,$dns] = syntheticApprovedTransport(['8.8.8.8'], fn () => throw new RuntimeException('No fake may reach wire'));
    expect(fn () => $transport->get('http://sports.core.api.espn.com/ref', ['sports.core.api.espn.com'], 100, true))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://sports.core.api.espn.com/ref');
    expect($dns->calls)->toBe(0);
});

it('keeps normal Laravel HTTP fakes hermetic while enforcing admission and response limits', function () {
    Http::preventStrayRequests();
    Http::fake(['https://assets.example/large' => Http::response(str_repeat('x', 101))]);
    [$transport,$dns] = syntheticApprovedTransport(['127.0.0.1'], fn () => throw new RuntimeException('No network'));
    expect(fn () => $transport->get('https://assets.example/large', ['assets.example'], 100))->toThrow(RuntimeException::class, 'byte limit')
        ->and($dns->calls)->toBe(0);
});

it('revalidates DNS on every admitted hop and bounds redirect loops', function () {
    [$transport, $dns] = syntheticApprovedTransport(['8.8.8.8'], function ($request, $options) {
        return Create::promiseFor(new Response(302, ['Location' => '/next']));
    });
    expect(fn () => $transport->get('https://assets.example/start', ['assets.example'], 100))->toThrow(RuntimeException::class, 'redirect limit');
    expect($dns->calls)->toBe(4);
});

it('aborts oversized actual-wire decoded chunks rather than returning a buffered response', function () {
    [$transport] = syntheticApprovedTransport(['8.8.8.8'], function ($request, $options) {
        $options['sink']->write(str_repeat('a', 80));
        $options['sink']->write(str_repeat('b', 30));

        return Create::promiseFor(new Response(200, [], $options['sink']));
    });
    expect(fn () => $transport->get('https://assets.example/large', ['assets.example'], 100))->toThrow(RuntimeException::class, 'byte limit');
});

it('rejects middleware rewritten destinations at the final wire boundary before DNS', function (string $rewritten) {
    Http::globalRequestMiddleware(fn ($request) => $request->withUri(new Uri($rewritten)));
    [$transport, $dns] = syntheticApprovedTransport(['8.8.8.8'], fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $transport->get('https://assets.example/image', ['assets.example'], 100))->toThrow(InvalidArgumentException::class)
        ->and($dns->calls)->toBe(0);
})->with(['https://unapproved.example/image', 'http://assets.example/image', 'https://assets.example:444/image']);

it('refuses a middleware rewritten mutation method before DNS or wire', function () {
    Http::globalRequestMiddleware(fn ($request) => $request->withMethod('POST'));
    [$transport, $dns] = syntheticApprovedTransport(['8.8.8.8'], fn () => throw new RuntimeException('Wire must not run'));
    expect(fn () => $transport->get('https://assets.example/image', ['assets.example'], 100))->toThrow(InvalidArgumentException::class, 'GET only')
        ->and($dns->calls)->toBe(0);
});

it('refuses rebinding to a private address on a same-host redirect before a second wire call', function () {
    $dns = new class extends DnsResolver
    {
        public int $calls = 0;

        public function addresses(string $host): array
        {
            return ++$this->calls === 1 ? ['8.8.8.8'] : ['127.0.0.1'];
        }
    };
    $transport = new class($dns) extends ApprovedHostGet
    {
        public int $calls = 0;

        protected function wire(RequestInterface $request, array $options): PromiseInterface
        {
            $this->calls++;

            return Create::promiseFor(new Response(302, ['Location' => '/next']));
        }
    };
    expect(fn () => $transport->get('https://assets.example/start', ['assets.example'], 100))->toThrow(InvalidArgumentException::class)
        ->and($transport->calls)->toBe(1)->and($dns->calls)->toBeGreaterThanOrEqual(2);
});

it('accepts semantically equivalent PSR URI encoding without changing opaque query ordering or reserved bytes', function () {
    Http::globalRequestMiddleware(fn ($request) => $request->withUri(new Uri('https://assets.example/~image?Token=A%2fb&x=1')));
    [$transport] = syntheticApprovedTransport(['8.8.8.8'], fn () => Create::promiseFor(new Response(200, [], 'body')));
    expect($transport->get('https://assets.example/%7Eimage?Token=A%2Fb&x=1', ['assets.example'], 100)->body())->toBe('body');
});

it('returns a single admitted hop without following its redirect for callers owning provenance policy', function () {
    [$transport, $dns] = syntheticApprovedTransport(['8.8.8.8'], function ($request, $options) {
        expect($options['timeout'])->toBeLessThanOrEqual(15);

        return Create::promiseFor(new Response(302, ['Location' => 'https://unapproved.example/body']));
    });
    expect($transport->get('https://assets.example/report', ['assets.example'], 100, followRedirects: false)->status())->toBe(302)
        ->and($dns->calls)->toBe(1);
});
