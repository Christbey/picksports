<?php

use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

it('refuses unapproved provider references before any HTTP request', function () {
    Http::fake();
    expect((new BaseEspnService('nfl'))->getByRef('https://unapproved.example/private'))->toBeNull();
    Http::assertNothingSent();
});

it('upgrades admitted legacy references and shares their HTTPS cache identity', function () {
    Http::fake(['https://sports.core.api.espn.com/ref' => Http::response(['id' => 'safe'])]);
    $service = new BaseEspnService('nfl');
    expect($service->getByRef('http://sports.core.api.espn.com/ref'))->toBe(['id' => 'safe'])
        ->and($service->getByRef('https://sports.core.api.espn.com/ref'))->toBe(['id' => 'safe']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://sports.core.api.espn.com/ref');
});

it('does not cache oversized or malformed successful provider bodies', function () {
    config()->set('espn.transport.maximum_bytes', 16);
    Http::fake(['https://sports.core.api.espn.com/ref' => Http::sequence()->push(str_repeat('x', 17))->push('not-json')->push(['id' => 'safe'])]);
    $service = new BaseEspnService('nfl');
    expect($service->getByRef('https://sports.core.api.espn.com/ref'))->toBeNull()
        ->and($service->getByRef('https://sports.core.api.espn.com/ref'))->toBeNull()
        ->and($service->getByRef('https://sports.core.api.espn.com/ref'))->toBe(['id' => 'safe']);
    Http::assertSentCount(3);
});
