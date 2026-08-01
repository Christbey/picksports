<?php

use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('requests adjusted team season stats with bearer auth', function () {
    config()->set('services.collegefootballdata.api_key', 'test-cfbd-key');
    config()->set('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');

    Http::fake(function (Request $request) {
        expect($request->url())->toStartWith('https://api.collegefootballdata.com/wepa/team/season')
            ->and($request->header('Authorization'))->toContain('Bearer test-cfbd-key')
            ->and($request['year'])->toBe(2025)
            ->and($request['conference'])->toBe('SEC')
            ->and($request->data())->not->toHaveKey('team');

        return Http::response([
            [
                'year' => 2025,
                'teamId' => 57,
                'team' => 'Georgia',
                'conference' => 'SEC',
                'explosiveness' => 1.24,
            ],
        ]);
    });

    $service = new CollegeFootballDataService;

    $results = $service->getAdjustedTeamSeasonStats(year: 2025, conference: 'SEC');

    expect($results)->toHaveCount(1)
        ->and($results[0]['team'])->toBe('Georgia')
        ->and($results[0]['conference'])->toBe('SEC');
});

it('requests preseason signal endpoints', function () {
    config()->set('services.collegefootballdata.api_key', 'test-cfbd-key');
    config()->set('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');

    Http::fake([
        'https://api.collegefootballdata.com/player/returning*' => Http::response([
            ['team' => 'Georgia', 'percentPPA' => 0.74],
        ]),
        'https://api.collegefootballdata.com/player/portal*' => Http::response([
            ['destination' => 'Georgia', 'position' => 'QB', 'rating' => 0.96],
        ]),
        'https://api.collegefootballdata.com/talent*' => Http::response([
            ['school' => 'Georgia', 'talent' => 985.4],
        ]),
        'https://api.collegefootballdata.com/recruiting/teams*' => Http::response([
            ['team' => 'Georgia', 'rank' => 1, 'points' => 315.2],
        ]),
    ]);

    $service = new CollegeFootballDataService;

    expect($service->getReturningProduction(year: 2026, conference: 'SEC')[0]['percentPPA'])->toBe(0.74)
        ->and($service->getTransferPortal(2026)[0]['position'])->toBe('QB')
        ->and($service->getTeamTalent(2026)[0]['talent'])->toBe(985.4)
        ->and($service->getTeamRecruitingRankings(year: 2026, team: 'Georgia')[0]['points'])->toBe(315.2);

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.collegefootballdata.com/player/returning')
            && $request['year'] === 2026
            && $request['conference'] === 'SEC'
            && $request->header('Authorization') === ['Bearer test-cfbd-key'];
    });

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.collegefootballdata.com/player/portal')
        && $request['year'] === 2026);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.collegefootballdata.com/talent')
        && $request['year'] === 2026);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.collegefootballdata.com/recruiting/teams')
        && $request['year'] === 2026
        && $request['team'] === 'Georgia');
});

it('requests advanced team season stats', function () {
    config()->set('services.collegefootballdata.api_key', 'test-cfbd-key');
    config()->set('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');

    Http::fake([
        'https://api.collegefootballdata.com/stats/season/advanced*' => Http::response([
            [
                'team' => 'Georgia',
                'offense' => [
                    'successRate' => 0.48,
                    'explosiveness' => 1.41,
                ],
            ],
        ]),
    ]);

    $service = new CollegeFootballDataService;

    expect($service->getAdvancedTeamSeasonStats(year: 2025, conference: 'SEC', excludeGarbageTime: true)[0]['team'])
        ->toBe('Georgia');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.collegefootballdata.com/stats/season/advanced')
        && $request['year'] === 2025
        && $request['conference'] === 'SEC'
        && $request['excludeGarbageTime'] === true);
});

it('throws when the api key is missing', function () {
    config()->set('services.collegefootballdata.api_key', null);

    $service = new CollegeFootballDataService;

    expect(fn () => $service->getAdjustedTeamSeasonStats(year: 2025))
        ->toThrow(RuntimeException::class, 'CollegeFootballData API key is not configured');
});

it('throws on failed responses', function () {
    config()->set('services.collegefootballdata.api_key', 'test-cfbd-key');
    config()->set('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');

    Http::fake([
        'https://api.collegefootballdata.com/wepa/team/season*' => Http::response([
            'message' => 'Unauthorized',
        ], 401),
    ]);

    $service = new CollegeFootballDataService;

    expect(fn () => $service->getAdjustedTeamSeasonStats(year: 2025))
        ->toThrow(RequestException::class);
});
