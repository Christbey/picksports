<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOdd;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportForecastQuery;
use App\Services\NFL\TeamFuturesProjectionService;
use App\Services\NFL\TeamPlayoffForecastService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/NflFutures.php';

it('conserves league wins, locks completed results and ties, and conditions seeds on qualification', function () {
    completeNflFuturesSchedule(2026, '2026-01-01T00:00:00Z');
    $games = Game::query()->orderBy('id')->get();
    $wins = [];
    $played = [];
    foreach ($games->take(48) as $index => $game) {
        $game->update(['status' => 'STATUS_FINAL', 'home_score' => 21, 'away_score' => $index === 0 ? 21 : 7, 'game_date' => '2026-10-01']);
        foreach ([$game->home_team_id, $game->away_team_id] as $id) {
            $played[$id] = ($played[$id] ?? 0) + 1;
        }
        if ($index !== 0) {
            $wins[$game->home_team_id] = ($wins[$game->home_team_id] ?? 0) + 1;
        }
    }
    $service = app(TeamPlayoffForecastService::class);
    $report = $service->forecast(2026, '2026-12-31', true, 500, 42);
    expect($report['teams'])->toBe($service->forecast(2026, '2026-12-31', true, 500, 42)['teams']);
    $teams = collect($report['teams']);
    expect($teams)->toHaveCount(32)
        ->and(abs($teams->sum('projected_wins') - 271))->toBeLessThan(0.02)
        ->and(abs($teams->sum('make_playoffs_probability') - 14))->toBeLessThan(0.002)
        ->and(abs($teams->sum('division_winner_probability') - 8))->toBeLessThan(0.002)
        ->and(abs($teams->sum('conference_champion_probability') - 2))->toBeLessThan(0.002)
        ->and(abs($teams->sum('super_bowl_champion_probability') - 1))->toBeLessThan(0.002);
    foreach ($teams as $team) {
        $id = $team['team_id'];
        expect($team['projected_wins'])->toBeGreaterThanOrEqual($wins[$id] ?? 0)
            ->toBeLessThanOrEqual(($wins[$id] ?? 0) + 17 - ($played[$id] ?? 0));
        if ($team['make_playoffs_probability'] > 0) {
            expect($team['projected_seed'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(7);
        } else {
            expect($team['projected_seed'])->toBeNull();
        }
        expect($team['super_bowl_champion_probability'])->toBeLessThanOrEqual($team['conference_champion_probability']);
    }
    // Once every game is complete, forecast wins must match the actual record exactly.
    Game::query()->where('status', 'STATUS_SCHEDULED')->update(['status' => 'STATUS_FINAL', 'home_score' => 14, 'away_score' => 7, 'game_date' => '2026-10-01']);
    $final = $service->forecast(2026, '2026-12-31', true, 100, 7);
    foreach ($final['teams'] as $team) {
        $actual = Game::query()->where('home_team_id', $team['team_id'])->whereColumn('home_score', '>', 'away_score')->count();
        expect($team['projected_wins'])->toEqual($actual);
    }
});

it('does not leak later completed results into historical simulations', function () {
    completeNflFuturesSchedule(2026, '2026-01-01T00:00:00Z');
    $service = app(TeamPlayoffForecastService::class);
    $before = $service->forecast(2026, '2026-08-01', true, 100, 7);
    Game::query()->update(['status' => 'STATUS_FINAL', 'home_score' => 99, 'away_score' => 0, 'game_date' => '2026-10-01']);
    expect($service->forecast(2026, '2026-08-01', true, 100, 7)['teams'])->toBe($before['teams']);
});

it('withholds probabilities when the schedule is incomplete', function () {
    completeNflFuturesSchedule(2026, '2026-01-01');
    Game::query()->first()->delete();
    $report = app(TeamPlayoffForecastService::class)->forecast(2026, '2026-08-01', true, 100);
    expect($report['teams'])->toBeEmpty()->and($report['warnings'])->not->toBeEmpty();
});

it('does not convert a scoring-total injury penalty into a win-strength penalty', function () {
    completeNflFuturesSchedule(2026, '2026-01-01');
    $service = app(TeamFuturesProjectionService::class);
    $before = $service->projections(2026, asOfDate: '2026-08-01', requireHistoricalMetrics: true);
    TeamMetricSnapshot::query()->update(['injury_total_adjustment' => -10]);
    $after = $service->projections(2026, asOfDate: '2026-08-01', requireHistoricalMetrics: true);
    expect(array_column($after, 'projected_total'))->toBe(array_column($before, 'projected_total'));
});

it('retains stored futures prices but suppresses stale and future-dated comparisons', function (int $age, bool $stale) {
    Cache::flush();
    $this->travelTo(now()->startOfSecond());
    $this->mock(TeamPlayoffForecastService::class)->shouldReceive('forecast')->once()->andReturn([
        'summary' => ['simulations' => 5000],
        'teams' => [['team_id' => 1, 'team_name' => 'Test', 'super_bowl_champion_probability' => 0.25]],
    ]);
    $team = Team::factory()->create(['id' => 1]);
    FuturesOdd::query()->create([
        'row_key' => sha1('freshness'), 'sport' => 'nfl', 'season' => 2026,
        'nfl_team_id' => $team->id, 'bookmaker' => 'draftkings', 'market_key' => 'outrights',
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner', 'outcome_name' => 'Test',
        'price' => 900, 'implied_probability' => 0.1, 'fetched_at' => now()->subSeconds($age),
    ]);
    $result = app(SportForecastQuery::class)->get(app(SportContextResolver::class)->resolve('nfl'), ['season' => 2026]);
    $row = $result['data'][0];
    expect($row['market_odds']['price'])->toBe(900)
        ->and($row['market_odds']['stale'])->toBe($stale)
        ->and($row['market_edge']['actionable'])->toBeFalse();
    if ($age === 36 * 3600) {
        $this->travel(1)->seconds();
        $cached = app(SportForecastQuery::class)->get(app(SportContextResolver::class)->resolve('nfl'), ['season' => 2026]);
        expect($cached['data'][0]['market_odds']['stale'])->toBeTrue()
            ->and($cached['data'][0]['market_edge']['edge_probability'])->toBeNull();
    }
    if ($stale) {
        expect($row['market_edge']['edge_probability'])->toBeNull()->and($row['market_edge']['has_edge'])->toBeFalse();
    } else {
        expect($row['market_edge']['edge_probability'])->toEqualWithDelta(0.15, 0.000001);
    }
})->with([[35 * 3600, false], [36 * 3600, false], [36 * 3600 + 1, true], [-3600, true]]);
