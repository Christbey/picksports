<?php

use App\Http\Middleware\UpdateUserLastActive;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\User;
use App\Support\SportsViewCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['subscriptions.enforce_tiers' => false, 'cache.default' => 'array']);
    app(SportsViewCache::class)->bustAll();
});

function createNflMatchupEndpointGame(array $attributes = []): Game
{
    return Game::factory()->create(array_replace([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'week' => 4,
        'game_date' => '2026-09-27', 'game_time' => '17:00:00',
        'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
        'home_score' => null, 'away_score' => null,
    ], $attributes));
}

it('requires authentication for NFL matchup signals', function () {
    $this->getJson('/api/v2/sports/nfl/games/1/matchup-signals')->assertUnauthorized();
});

it('is a read only sidecar with isolated windows and no provider requests', function () {
    // Global user-activity bookkeeping is unrelated to matchup/model writes.
    $this->withoutMiddleware(UpdateUserLastActive::class);
    Sanctum::actingAs(User::factory()->create());
    $game = createNflMatchupEndpointGame();
    Http::preventStrayRequests();
    Http::fake();
    $before = $game->fresh()->getRawOriginal();
    $url = "/api/v2/sports/nfl/games/{$game->id}/matchup-signals";
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach (['season_to_date' => 2026, 'previous_season' => 2025] as $window => $season) {
        $this->getJson($url.'?window='.$window)->assertOk()
            ->assertJsonPath('data.role', 'independent_matchup_analysis')
            ->assertJsonPath('data.affects_prediction', false)
            ->assertJsonPath('data.matchup.season', $season)
            ->assertJsonPath('data.matchup.window', $window)
            ->assertJsonPath('data.matchup.prediction_effect', ['winner' => false, 'spread' => false, 'total' => false, 'confidence' => false, 'approval' => false])
            ->assertJsonCount(353, 'data.matchup.catalog');
    }
    $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']));
    DB::disableQueryLog();
    expect($writes->pluck('query')->all())->toBe([])
        ->and($game->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
    $this->getJson($url.'?window=blended')->assertUnprocessable();
});

it('rejects non NFL matchup signal requests', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nba/games/1/matchup-signals')->assertNotFound();
});

it('returns not found for an absent NFL matchup game', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nfl/games/9999999/matchup-signals')->assertNotFound();
});

it('returns descriptive matchup evidence and situational records without approving wagers', function () {
    Sanctum::actingAs(User::factory()->create());
    $game = createNflMatchupEndpointGame();

    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/matchup-signals")
        ->assertOk()
        ->assertJsonPath('meta.sport', 'nfl')
        ->assertJsonPath('meta.contract', 'sports.games.matchup-signals.show')
        ->assertJsonPath('meta.game_id', $game->id)
        ->assertJsonPath('meta.cache_ttl_seconds', 120)
        ->assertJsonPath('data.matchup.mode', 'descriptive_only')
        ->assertJsonPath('data.matchup.predictive_weight', 0)
        ->assertJsonPath('data.matchup.cutoff_at', '2026-09-27T17:00:00+00:00')
        ->assertJsonPath('data.matchup.summary.matched', 0)
        ->assertJsonPath('data.situational.home.team_id', $game->home_team_id)
        ->assertJsonPath('data.situational.away.team_id', $game->away_team_id)
        ->assertJsonPath('data.situational.home.market_records.ats.status', 'unavailable')
        ->assertJsonPath('data.situational.home.market_records.totals.status', 'unavailable')
        ->assertJsonStructure(['data' => [
            'generated_at',
            'matchup' => ['version', 'limitations', 'catalog', 'signals' => ['*' => [
                'id', 'status', 'offense_team_id', 'defense_team_id', 'reason', 'evidence',
            ]]],
            'situational' => ['home' => ['records'], 'away' => ['records']],
        ]]);
});

it('reuses cached matchup payloads without repeating league or team history queries', function () {
    Sanctum::actingAs(User::factory()->create());
    $game = createNflMatchupEndpointGame();
    createNflMatchupEndpointGame([
        'home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'week' => 3, 'game_date' => '2026-09-20',
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17,
    ]);
    $url = "/api/v2/sports/nfl/games/{$game->id}/matchup-signals";
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = $this->getJson($url)->assertOk();
    $firstQueries = collect(DB::getQueryLog())->pluck('query');
    expect($firstQueries->filter(fn ($sql) => str_contains($sql, 'nflverse_pbp_plays')))->toHaveCount(1);

    DB::flushQueryLog();
    $second = $this->getJson($url)->assertOk();
    $secondQueries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($second->json('data'))->toBe($first->json('data'))
        ->and($secondQueries->filter(fn ($sql) => str_contains($sql, 'nflverse_pbp_plays')))->toHaveCount(0)
        // The access/game lookup still executes before the cached payload is read.
        ->and($secondQueries->filter(fn ($sql) => str_contains($sql, 'from "nfl_games"')))->toHaveCount(1)
        ->and($secondQueries->count())->toBeLessThan($firstQueries->count());
});

it('bounds situational evidence before kickoff and conservatively excludes same day history', function () {
    Sanctum::actingAs(User::factory()->create());
    $game = createNflMatchupEndpointGame([
        'status' => 'STATUS_FINAL', 'home_score' => 48, 'away_score' => 10,
    ]);
    $sameTeams = ['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'status' => 'STATUS_FINAL', 'home_score' => 21, 'away_score' => 14];
    $prior = createNflMatchupEndpointGame($sameTeams + [
        'week' => 3, 'game_date' => '2026-09-20',
    ]);
    $later = createNflMatchupEndpointGame($sameTeams + [
        'week' => 5, 'game_date' => '2026-10-04',
    ]);
    // Even a currently final earlier kickoff is excluded: stored results have
    // no verified completion timestamp proving they were available at cutoff.
    $unknownFinish = createNflMatchupEndpointGame($sameTeams + [
        'game_time' => '15:00:00',
    ]);

    $response = $this->getJson("/api/v2/sports/nfl/games/{$game->id}/matchup-signals")->assertOk();
    $home = collect($response->json('data.situational.home.records'))->keyBy('id');
    $away = collect($response->json('data.situational.away.records'))->keyBy('id');
    expect($home['home']['game_ids'])->toBe([$prior->id])
        ->and($home['home']['record'])->toBe(['wins' => 1, 'losses' => 0, 'ties' => 0])
        ->and($away['road']['game_ids'])->toBe([$prior->id]);

    foreach (['home', 'away'] as $side) {
        $ids = collect($response->json("data.situational.{$side}.records"))->pluck('game_ids')->flatten()->all();
        expect($ids)->not->toContain($game->id, $later->id, $unknownFinish->id);
    }
});
