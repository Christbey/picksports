<?php

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\Predictions\HistoricalForecastArchive;
use Laravel\Sanctum\Sanctum;

function nbaArchiveFixture(): array
{
    config(['prediction_lifecycle.canonical_reads.nba' => true, 'subscriptions.enforce_tiers' => false]);
    $kickoff = now()->utc()->subWeek()->startOfHour();
    CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame', 'effective_at' => now()->subDay()]);
    $event = SportEvent::factory()->create(['sport' => 'nba', 'starts_at' => $kickoff]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'sport_event_id' => $event->id, 'status' => 'STATUS_FINAL', 'home_score' => 110, 'away_score' => 100, 'game_date' => $kickoff, 'game_time' => $kickoff->toTimeString()]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 4.5, 'predicted_total' => 215, 'win_probability' => .6]);

    return [$game, $legacy, $kickoff];
}

it('preserves unverified stored NBA history separately without claiming safe pregame provenance', function () {
    [$game, $legacy] = nbaArchiveFixture();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v2/sports/nba/games/{$game->id}")->assertSuccessful()
        ->assertJsonPath('historical_prediction.source', 'legacy_history')
        ->assertJsonPath('historical_prediction.prediction_id', $legacy->id)
        ->assertJsonPath('historical_prediction.known_before_kickoff', false)
        ->assertJsonPath('historical_prediction.snapshot_id', null)
        ->assertJsonMissingPath('historical_prediction.betting_value');
    expect(app(SportPredictionReader::class)->forLoadedGame(app(SportContextResolver::class)->resolve('nba'), $game))->toBeNull()
        ->and(CanonicalPrediction::count())->toBe(0);
});

it('prefers immutable observed NBA snapshots to overwritten historical rows', function () {
    [$game, $legacy, $kickoff] = nbaArchiveFixture();
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'nba', 'prediction_table' => 'nba_predictions', 'prediction_id' => $legacy->id,
        'game_id' => $game->id, 'model_version' => 'observed', 'feature_version' => 'test', 'blend_version' => 'test',
        'features' => [], 'outputs' => ['predicted_spread' => 3.5, 'predicted_total' => 210, 'win_probability' => .55],
        'generated_at' => $kickoff->subHour(), 'features_available_at' => $kickoff->subHours(2), 'game_start_at' => $kickoff,
        'pregame_safe' => true, 'availability_status' => 'observed_pregame']);
    $result = app(HistoricalForecastArchive::class)->forGame($game, 'nba');
    expect($result['source'])->toBe('legacy_archive')->and($result['snapshot_id'])->toBe($snapshot->id)
        ->and($result['known_before_kickoff'])->toBeTrue()->and($result['predicted_spread'])->toBe(3.5);
    $snapshot->forceFill(['pregame_safe' => false])->save();
    expect(app(HistoricalForecastArchive::class)->forGame($game, 'nba')['known_before_kickoff'])->toBeFalse();
});

it('never uses historical NBA forecasts over native revisions or for active and post-cutover games', function (string $variant) {
    [$game] = nbaArchiveFixture();
    if (in_array($variant, ['draft', 'withdrawn', 'superseded'], true)) {
        CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $game->sport_event_id, 'phase' => 'pregame', 'publication_state' => $variant]);
    } elseif ($variant === 'active') {
        $game->status = 'STATUS_SCHEDULED';
    } elseif ($variant === 'post_cutover') {
        $game->game_date = now();
        $game->game_time = now()->utc()->toTimeString();
    } else {
        config(['prediction_lifecycle.canonical_reads.nba' => false]);
    }
    expect(app(HistoricalForecastArchive::class)->forGame($game, 'nba'))->toBeNull();
})->with(['draft', 'withdrawn', 'superseded', 'active', 'post_cutover', 'legacy_mode']);

it('retains NBA history authentication and subscription permission gates', function () {
    [$game] = nbaArchiveFixture();
    $this->getJson("/api/v2/sports/nba/games/{$game->id}")->assertUnauthorized();
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v2/sports/nba/games/{$game->id}")->assertForbidden();
});
