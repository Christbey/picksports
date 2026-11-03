<?php

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\NFL\NflHistoricalForecastArchive;
use Laravel\Sanctum\Sanctum;

function nflHistoricalArchiveFixture(): array
{
    config(['prediction_lifecycle.canonical_reads.nfl' => true, 'subscriptions.enforce_tiers' => false]);
    $start = now()->utc()->subWeek()->startOfHour();
    CalculationRelease::factory()->approved()->create(['sport' => 'nfl', 'phase' => 'pregame', 'effective_at' => now()->subDay()]);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'starts_at' => $start]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17, 'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString()]);
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 123,
        'game_id' => $game->id, 'model_version' => 'observed-history', 'feature_version' => 'test', 'blend_version' => 'test',
        'features' => [], 'outputs' => ['predicted_spread' => 3.5, 'predicted_total' => 43, 'win_probability' => 0.6],
        'generated_at' => $start->copy()->subHour(), 'features_available_at' => $start->copy()->subHours(2),
        'game_start_at' => $start, 'pregame_safe' => true, 'availability_status' => 'observed_pregame']);

    return [$game, $snapshot];
}

it('preserves immutable pre-cutover forecasts separately from the active game-page prediction', function () {
    [$game, $snapshot] = nflHistoricalArchiveFixture();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertSuccessful()
        ->assertJsonPath('data.prediction', null)->assertJsonPath('meta.prediction_source', null)
        ->assertJsonPath('data.historical_prediction.source', 'legacy_archive')
        ->assertJsonPath('data.historical_prediction.snapshot_id', $snapshot->id)
        ->assertJsonPath('data.historical_prediction.predicted_spread', 3.5)
        ->assertJsonPath('data.historical_prediction.known_before_kickoff', true)
        ->assertJsonMissingPath('data.historical_prediction.betting_value')
        ->assertJsonMissingPath('data.historical_prediction.model_metadata');
    expect(CanonicalPrediction::count())->toBe(0);
});

it('never archives over any native revision including draft withdrawn or invalidated evidence', function (string $state) {
    [$game] = nflHistoricalArchiveFixture();
    CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $game->sport_event_id, 'phase' => 'pregame', 'publication_state' => $state]);
    expect(app(NflHistoricalForecastArchive::class)->forGame($game))->toBeNull();
})->with(['draft', 'withdrawn', 'superseded']);

it('excludes active post-cutover unsafe reconstructed and mismatched archive records', function (string $variant) {
    [$game, $snapshot] = nflHistoricalArchiveFixture();
    if ($variant === 'active') {
        $game->forceFill(['status' => 'STATUS_SCHEDULED'])->saveQuietly();
    } elseif ($variant === 'post_cutover') {
        $game->forceFill(['game_date' => now()->toDateString(), 'game_time' => now()->utc()->toTimeString()])->saveQuietly();
    } elseif ($variant === 'compatibility') {
        config(['prediction_lifecycle.canonical_reads.nfl' => false]);
    } else {
        $attributes = match ($variant) {
            'unsafe' => ['pregame_safe' => false],
            'reconstructed' => ['availability_status' => 'verified_reconstruction'],
            'kickoff_mismatch' => ['game_start_at' => $snapshot->game_start_at->copy()->addHour()],
        };
        PredictionFeatureSnapshot::whereKey($snapshot->id)->update($attributes);
    }
    expect(app(NflHistoricalForecastArchive::class)->forGame($game->fresh()))->toBeNull();
})->with(['active', 'post_cutover', 'compatibility', 'unsafe', 'reconstructed', 'kickoff_mismatch']);

it('keeps archive output behind existing game-page authentication and subscription access', function () {
    [$game] = nflHistoricalArchiveFixture();
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertUnauthorized();
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/page")->assertForbidden();
});
