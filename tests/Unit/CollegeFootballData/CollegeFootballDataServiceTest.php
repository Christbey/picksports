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
