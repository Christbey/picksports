<?php

use App\Services\ESPN\BaseEspnService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('espn.cache.enabled', true);
    config()->set('espn.cache.ttl_minutes', 5);
    config()->set('espn.cache.max_payload_bytes', 1024);
    config()->set('espn.cache.scoreboard_max_payload_bytes', 4096);
});

it('caches a small reusable reference response', function () {
    Http::fake([
        'https://sports.core.api.espn.com/reference/1' => Http::response(['id' => '1']),
    ]);

    $service = new BaseEspnService('cfb');

    expect($service->getByRef('https://sports.core.api.espn.com/reference/1'))->toBe(['id' => '1'])
        ->and($service->getByRef('https://sports.core.api.espn.com/reference/1'))->toBe(['id' => '1']);

    Http::assertSentCount(1);
});

it('does not cache a reference response over the configured size ceiling', function () {
    config()->set('espn.cache.max_payload_bytes', 128);
    Http::fake([
        'https://sports.core.api.espn.com/reference/large' => Http::response([
            'items' => [str_repeat('x', 512)],
        ]),
    ]);

    $service = new BaseEspnService('cfb');

    $service->getByRef('https://sports.core.api.espn.com/reference/large');
    $service->getByRef('https://sports.core.api.espn.com/reference/large');

    Http::assertSentCount(2);
});

it('never caches event summaries even when they are small', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/college-football/summary*' => Http::response([
            'header' => ['id' => '401000001'],
        ]),
    ]);

    $service = new BaseEspnService('cfb');

    $service->getGame('401000001');
    $service->getGame('401000001');

    Http::assertSentCount(2);
});

it('never caches play-by-play even when it is small', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/college-football/events/401000001/competitions/401000001/plays*' => Http::response([
            'items' => [],
        ]),
    ]);

    $service = new BaseEspnService('cfb');

    $service->getPlays('401000001', '401000001');
    $service->getPlays('401000001', '401000001');

    Http::assertSentCount(2);
});

it('retains a separate larger cache allowance for scoreboards', function () {
    $scoreboard = ['events' => [['id' => '1', 'name' => str_repeat('x', 1500)]]];
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard*' => Http::response($scoreboard),
    ]);

    $service = new BaseEspnService('cfb');

    expect($service->getScoreboard('20260913'))->toBe($scoreboard)
        ->and($service->getScoreboard('20260913'))->toBe($scoreboard);

    Http::assertSentCount(1);
});

it('can disable ESPN response caching globally', function () {
    config()->set('espn.cache.enabled', false);
    Http::fake(fn (Request $request) => Http::response(['url' => $request->url()]));

    $service = new BaseEspnService('cfb');

    $service->getByRef('https://sports.core.api.espn.com/reference/1');
    $service->getByRef('https://sports.core.api.espn.com/reference/1');

    Http::assertSentCount(2);
});

it('clears only the current sport cache tag', function () {
    Http::fake([
        'https://sports.core.api.espn.com/cfb/reference' => Http::response(['sport' => 'cfb']),
        'https://sports.core.api.espn.com/nfl/reference' => Http::response(['sport' => 'nfl']),
    ]);

    $cfb = new BaseEspnService('cfb');
    $nfl = new BaseEspnService('nfl');

    $cfb->getByRef('https://sports.core.api.espn.com/cfb/reference');
    $nfl->getByRef('https://sports.core.api.espn.com/nfl/reference');
    $cfb->clearCache();
    $cfb->getByRef('https://sports.core.api.espn.com/cfb/reference');
    $nfl->getByRef('https://sports.core.api.espn.com/nfl/reference');

    Http::assertSentCount(3);
});
