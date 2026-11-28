<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;

beforeEach(function () {
    $this->withoutVite();
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $analyzer = Mockery::mock(PlayerPropAnalyzer::class);
    foreach (['getAvailableDatesForSport', 'getAvailableGamesForSport', 'getAvailableMarketsForSport', 'analyzeProps'] as $method) {
        $analyzer->shouldReceive($method)->andReturn(collect());
    }
    $this->app->instance(PlayerPropAnalyzer::class, $analyzer);
});

it('does not invent a home pick or probability for a stored unknown prediction', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    Prediction::query()->create(['game_id' => $game->id, 'win_probability' => null, 'confidence_score' => null]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.win_probability', null)->where('predictions.0.pick_side', null)->where('predictions.0.pick_team', null));
});

it('keeps missing prediction confidence unknown', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    Prediction::query()->create(['game_id' => $game->id, 'win_probability' => 0.4, 'confidence_score' => null]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.confidence', null)->where('predictions.0.win_probability', 40)->where('predictions.0.pick_side', 'Away'));
});

it('retains a genuine zero home probability and zero confidence', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    Prediction::query()->create(['game_id' => $game->id, 'win_probability' => 0, 'confidence_score' => 0]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.confidence', 0)->where('predictions.0.win_probability', 0)->where('predictions.0.pick_side', 'Away'));
});

it('exports the real stored UTC clock instead of midnight from a date-only cast', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'season' => 2026, 'game_date' => '2026-11-04', 'game_time' => '19:45:00']);
    Prediction::query()->create(['game_id' => $game->id, 'win_probability' => 0.6, 'confidence_score' => 80]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.game.time', '19:45 UTC')->where('predictions.0.game.starts_at', '2026-11-04T19:45:00+00:00'));
});

it('does not export an unqualified legacy MLB wall clock as an observed kickoff', function () {
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $game = App\Models\MLB\Game::factory()->create(['home_team_id' => App\Models\MLB\Team::factory()->create()->id, 'away_team_id' => App\Models\MLB\Team::factory()->create()->id, 'season' => 2026, 'game_date' => '2026-11-04', 'game_time' => '19:45:00']);
    App\Models\MLB\Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'win_probability' => 0.6, 'confidence_score' => 80]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions', 'sport' => 'MLB']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.game.time', null)->where('predictions.0.game.starts_at', null));
});

it('preserves NFL missing-import-clock evidence without rejecting a genuine midnight', function (bool $missing) {
    config(['prediction_lifecycle.canonical_reads.nfl' => false]);
    $game = App\Models\NFL\Game::factory()->create([
        'home_team_id' => App\Models\NFL\Team::factory()->create()->id,
        'away_team_id' => App\Models\NFL\Team::factory()->create()->id,
        'season' => 2026, 'game_date' => '2026-11-04', 'game_time' => '00:00:00', 'nflverse_game_id' => '2026_02_A_B',
        'odds_data' => ['event_id' => 'nflverse:2026_02_A_B', 'commence_time' => $missing ? null : '2026-11-04T00:00:00Z',
            'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']],
    ]);
    App\Models\NFL\Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'win_probability' => 0.6, 'confidence_score' => 80]);
    $time = $missing ? null : '00:00 UTC';
    $start = $missing ? null : '2026-11-04T00:00:00+00:00';
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions', 'sport' => 'NFL']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.game.time', $time)->where('predictions.0.game.starts_at', $start)->where('predictionGames.0.time', $time));
})->with([true, false]);

it('retains the CBB stored UTC clock and decimal projection values', function () {
    config(['prediction_lifecycle.canonical_reads.cbb' => false]);
    $game = App\Models\CBB\Game::factory()->create([
        'home_team_id' => App\Models\CBB\Team::factory()->create()->id,
        'away_team_id' => App\Models\CBB\Team::factory()->create()->id,
        'season' => 2026, 'game_date' => '2026-11-04', 'game_time' => '19:45:00',
    ]);
    App\Models\CBB\Prediction::query()->create(['game_id' => $game->id, 'season' => 2026, 'win_probability' => 0.6, 'confidence_score' => 80, 'predicted_spread' => 1.8, 'predicted_total' => 140.5]);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['tab' => 'predictions', 'sport' => 'CBB']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('predictions.0.game.time', '19:45 UTC')->where('predictions.0.game.starts_at', '2026-11-04T19:45:00+00:00')->where('predictions.0.predicted_spread', '1.8')->where('predictions.0.predicted_total', '140.5'));
});
