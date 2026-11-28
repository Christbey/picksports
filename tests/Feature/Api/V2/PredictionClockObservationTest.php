<?php

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Models\User;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalReadFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.timezone' => 'America/Chicago', 'prediction_lifecycle.canonical_reads.nfl' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00-06:00'));
    $user = User::factory()->admin()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
});

function predictionClockObservationGame(?string $clock, ?array $odds = null): Game
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'season' => 2026, 'season_type' => '2', 'game_date' => '2026-11-27', 'game_time' => $clock, 'status' => 'STATUS_SCHEDULED', 'nflverse_game_id' => $odds === null ? null : 'synthetic-unknown-clock', 'odds_data' => $odds]);
    $attributes = ['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500, 'predicted_spread' => 0, 'predicted_total' => 0, 'win_probability' => 0.5, 'confidence_score' => 50, 'model_version' => 'synthetic-clock'];
    Prediction::query()->create(array_intersect_key($attributes, array_flip(Schema::getColumnListing('nfl_predictions'))));

    return $game;
}

it('keeps NFL prediction kickoff and research retry unavailable when the current clock is unobserved', function (?string $clock, ?array $odds) {
    $game = predictionClockObservationGame($clock, $odds);
    $response = $this->getJson('/api/v2/sports/nfl/games/'.$game->id.'/prediction')->assertOk()->assertJsonPath('data.game.starts_at', null);
    expect([$response->json('data.game.kickoff_at'), $response->json('data.nfl_board.can_retry_research')])->toBe([null, false]);
})->with([
    'nullable missing clock' => [null, null],
    'identified old imported carrier' => ['00:00:00', ['event_id' => 'nflverse:synthetic-unknown-clock', 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]],
]);

it('preserves an explicit stored NFL midnight and future retry affordance', function () {
    $game = predictionClockObservationGame('00:00:00');
    $this->getJson('/api/v2/sports/nfl/games/'.$game->id.'/prediction')->assertOk()->assertJsonPath('data.game.starts_at', '2026-11-27T00:00:00+00:00')->assertJsonPath('data.game.kickoff_at', '2026-11-27T00:00:00+00:00')->assertJsonPath('data.nfl_board.can_retry_research', true)->assertJsonPath('data.predicted_spread', 0)->assertJsonPath('data.predicted_total', 0);
});

it('preserves the NFL kickoff field contract for a published canonical forecast', function () {
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $start = CarbonImmutable::parse('2026-11-27T00:00:00Z');
    $game = predictionClockObservationGame('00:00:00');
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026, 'starts_at' => LegacyTimestampCarrier::forInstant($start)]);
    $game->update(['sport_event_id' => $event->id]);
    $run = CanonicalReadFixture::run($event, ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]]);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id, 'output_hash' => $run->output_hash, 'generated_at' => $run->inputSnapshot->captured_at, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);
    $this->getJson('/api/v2/sports/nfl/games/'.$game->id.'/prediction')->assertOk()->assertJsonPath('data.game.starts_at', '2026-11-27T00:00:00+00:00')->assertJsonPath('data.game.kickoff_at', '2026-11-27T00:00:00+00:00');
});
