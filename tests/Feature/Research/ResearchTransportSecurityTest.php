<?php

use App\Models\NFL\ResearchDocument as NflDocument;
use App\Models\NFL\ResearchSource as NflSource;
use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Services\Http\ApprovedHostGet;
use App\Services\Http\DnsResolver;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\Research\ResearchDocumentStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    // A regression back to the old direct Http path must fail before DNS/socket.
    // The approved transport supplies these options before its injected wire seam.
    Http::globalMiddleware(fn ($next) => function ($request, $options) use ($next) {
        if (($options['protocols'] ?? null) !== ['https'] || ($options['proxy'] ?? null) !== '') {
            throw new RuntimeException('Test refuses an unprotected HTTP transport.');
        }

        return $next($request, $options);
    });
});

function researchSecurityTransport(array $answers, Closure $wire): array
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
    $transport = new class($dns, $wire) extends ApprovedHostGet
    {
        public int $calls = 0;

        public function __construct(DnsResolver $dns, private Closure $response)
        {
            parent::__construct($dns);
        }

        protected function wire(RequestInterface $request, array $options): PromiseInterface
        {
            $this->calls++;

            return ($this->response)($request, $options);
        }
    };
    app()->instance(ApprovedHostGet::class, $transport);

    return [$transport, $dns];
}

function invokeSecurityResearchFetch(string $consumer): mixed
{
    if ($consumer === 'official') {
        config(['nfl_research.teams' => ['AAA' => 'source.example']]);
        $source = NflSource::create(['key' => 'AAA:injury', 'team' => 'AAA', 'kind' => 'injury', 'url' => 'https://source.example/report']);

        return app(OfficialSourceIngestor::class)->poll($source);
    }

    return app(ResearchDocumentStore::class)->fetchWithProvenance(new ResearchSource([
        'enabled' => true, 'url' => 'https://source.example/report', 'allowed_hosts' => ['source.example'],
    ]));
}

it('rejects privately resolved approved research hosts before any wire request or document write', function (string $consumer) {
    [$transport, $dns] = researchSecurityTransport(['8.8.8.8', '127.0.0.1'], fn () => throw new RuntimeException('No wire'));
    expect(fn () => invokeSecurityResearchFetch($consumer))->toThrow(InvalidArgumentException::class)
        ->and($transport->calls)->toBe(0)->and($dns->calls)->toBeGreaterThan(0)
        ->and(ResearchDocument::count())->toBe(0)->and(NflDocument::count())->toBe(0);
})->with(['shared', 'official']);

it('stops oversized decoded research chunks before growing beyond the owning document budget', function (string $consumer, int $maximum) {
    [$transport] = researchSecurityTransport(['8.8.8.8'], function ($request, $options) use ($maximum) {
        $options['sink']->write(str_repeat('x', $maximum - 10));
        try {
            $options['sink']->write(str_repeat('x', 20));
        } finally {
            expect($options['sink']->getSize())->toBe($maximum - 10);
        }

        return Create::promiseFor(new Response(200, [], $options['sink']));
    });
    expect(fn () => invokeSecurityResearchFetch($consumer))->toThrow(RuntimeException::class, 'byte limit')
        ->and(ResearchDocument::count())->toBe(0)->and(NflDocument::count())->toBe(0);
})->with([['shared', 250000], ['official', 3000000]]);

it('refuses middleware path rewriting instead of attributing the returned body to the admitted research URL', function (string $consumer) {
    Http::globalRequestMiddleware(fn ($request) => $request->withUri($request->getUri()->withPath('/different-report')));
    [$transport, $dns] = researchSecurityTransport(['8.8.8.8'], fn () => Create::promiseFor(new Response(200, [], '<main>'.str_repeat('Different report. ', 10).'</main>')));
    expect(fn () => invokeSecurityResearchFetch($consumer))->toThrow(InvalidArgumentException::class, 'changed after admission')
        ->and($transport->calls)->toBe(0)->and($dns->calls)->toBe(0)
        ->and(ResearchDocument::count())->toBe(0)->and(NflDocument::count())->toBe(0);
})->with(['shared', 'official']);
