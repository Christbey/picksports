<?php

use App\Actions\Validation\Checks\PlayerPropFreshnessCheck;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\NflPlayerPropCoverage;

beforeEach(fn () => $this->travelTo('2026-09-16 12:00:00'));
afterEach(fn () => $this->travelBack());

function nflCoverageGame(): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-17', 'game_time' => '23:15:00',
        'odds_api_event_id' => 'coverage-event', 'odds_updated_at' => now(),
    ]);
}

function nflCoverageProp(Game $game, ?string $status = null, ?string $reason = null): PlayerProp
{
    $prop = PlayerProp::create([
        'game_id' => $game->id, 'player_name' => 'Example Quarterback',
        'market' => 'player_pass_yds', 'bookmaker' => 'testbook',
        'line' => 250.5, 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now(),
    ]);
    if ($status !== null) {
        $prop->update(['confidence_decomposition' => ['analysis_disposition' => [
            'status' => $status, 'reason' => $reason, 'evaluated_at' => now()->toIso8601String(),
            'model_version' => PlayerPropAnalyzer::NFL_MODEL_VERSION,
            'quote_fingerprint' => app(NflPlayerPropCoverage::class)->quoteFingerprint($prop),
        ]]]);
    }
    if ($status === 'scored') {
        $prop->update(['recommended_side' => 'Over', 'confidence_score' => 65,
            'predicted_over_probability' => 61, 'market_over_probability' => 52,
            'edge_probability' => 9, 'data_quality_score' => 70]);
    }

    return $prop;
}

test('one fresh scored NFL quote cannot hide an unprocessed stale quote', function () {
    $game = nflCoverageGame();
    nflCoverageProp($game, 'scored');
    nflCoverageProp($game)->update(['fetched_at' => now()->subDay()->subSecond()]);

    $result = app(PlayerPropFreshnessCheck::class)->run('nfl', config('validation.sports.nfl'));
    expect($result['status'])->toBe('failing')
        ->and(data_get($result, 'metadata.quote_coverage.quotes'))->toBe(2)
        ->and(data_get($result, 'metadata.quote_coverage.scored_quotes'))->toBe(1)
        ->and(data_get($result, 'metadata.quote_coverage.unprocessed_quotes'))->toBe(1)
        ->and(data_get($result, 'metadata.quote_coverage.stale_quotes'))->toBe(1);
});

test('NFL props remain fresh through 24 hours and expire afterward without changing timestamps', function (int $ageSeconds, int $staleQuotes) {
    $game = nflCoverageGame();
    $prop = nflCoverageProp($game, 'scored');
    $fetchedAt = now()->subSeconds($ageSeconds);
    $prop->update(['fetched_at' => $fetchedAt]);

    $result = app(PlayerPropFreshnessCheck::class)->run('nfl', config('validation.sports.nfl'));

    expect(data_get($result, 'metadata.stale_after_hours'))->toBe(24)
        ->and(data_get($result, 'metadata.quote_coverage.stale_quotes'))->toBe($staleQuotes)
        ->and(app(NflPlayerPropCoverage::class)->forGame($game->id)['stale_quotes'])->toBe($staleQuotes)
        ->and($prop->fresh()->fetched_at->equalTo($fetchedAt))->toBeTrue()
        ->and(config('validation.thresholds.player_prop_freshness.stale_after_hours'))->toBe(12);
})->with(['18 hours' => [18 * 3600, 0], '24 hours' => [24 * 3600, 0], 'expired' => [24 * 3600 + 1, 1]]);

test('evaluated no-edge holds count as processed while data holds remain visible', function () {
    $game = nflCoverageGame();
    nflCoverageProp($game, 'hold', 'no_edge');
    $check = app(PlayerPropFreshnessCheck::class);
    $result = $check->run('nfl', config('validation.sports.nfl'));
    expect($result['status'])->toBe('passing')
        ->and(data_get($result, 'metadata.quote_coverage.no_edge_quotes'))->toBe(1)
        ->and(data_get($result, 'metadata.quote_coverage.scored_quotes'))->toBe(0)
        ->and(data_get($result, 'metadata.quote_coverage.unprocessed_quotes'))->toBe(0);

    nflCoverageProp($game, 'hold', 'insufficient_history');
    $result = $check->run('nfl', config('validation.sports.nfl'));
    expect($result['status'])->toBe('warning')
        ->and(data_get($result, 'metadata.games_with_evaluated_data_holds'))->toBe(1)
        ->and(data_get($result, 'metadata.quote_coverage.data_hold_quotes'))->toBe(1);
});

test('changed NFL quotes invalidate analysis disposition and only-missing includes partly analyzed games', function () {
    $game = nflCoverageGame();
    nflCoverageProp($game, 'scored');
    $prop = nflCoverageProp($game, 'hold', 'no_edge');
    $prop->update(['line' => 265.5]);
    expect(app(NflPlayerPropCoverage::class)->forGame($game->id)['unprocessed_quotes'])->toBe(1);

    $this->mock(PlayerPropAnalyzer::class, function ($mock) use ($game) {
        $mock->shouldReceive('analyzeProps')->once()->with('NFL', 3, null, $game->id, null, false)->andReturn(collect());
    });
    $this->artisan('sports:analyze-player-props', ['--sport' => 'nfl', '--season' => 2026, '--only-missing' => true])
        ->expectsOutput('Only-missing mode: 1/1 active game(s) need recommendation analysis.')->assertSuccessful();
});

test('NFL analysis persists an honest hold when the player cannot be matched', function () {
    $game = nflCoverageGame();
    $prop = nflCoverageProp($game);
    expect(app(PlayerPropAnalyzer::class)->analyzeProps('NFL', 3, null, $game->id, null, false))->toHaveCount(0);
    expect(data_get($prop->fresh()->confidence_decomposition, 'analysis_disposition.reason'))->toBe('player_unmatched')
        ->and($prop->fresh()->recommended_side)->toBeNull()
        ->and(app(NflPlayerPropCoverage::class)->forGame($game->id)['held_quotes'])->toBe(1);
});
