<?php

use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Models\User;
use App\Support\MLB\MlbGameStart;
use App\Support\NFL\NflGameStart;
use App\Support\Sports\LegacyTimestampCarrier;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalReadFixture;
use Tests\Support\ObservedMlbGameFixture;

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00-06:00'));
    $user = User::factory()->create();
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => [$user->id]]);
    foreach (['nba', 'cbb', 'cfb', 'nfl', 'mlb', 'wcbb', 'wnba'] as $sport) {
        config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    }
    Sanctum::actingAs($user);
    app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_LIVE_SCOREBOARD);
});

function scoreboardWindowGame(string $sport, array $attributes): Model
{
    $gameClass = 'App\\Models\\'.$sport.'\\Game';
    $predictionClass = 'App\\Models\\'.$sport.'\\Prediction';
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $home = $teamClass::factory()->create();
    $away = $teamClass::factory()->create();
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'status' => 'STATUS_IN_PROGRESS', ...$attributes]);
    $game->getConnection()->table($game->getTable())->where('id', $game->id)->update(['game_date' => $attributes['game_date']]);
    $game->refresh();
    $columns = array_flip(Schema::getColumnListing((new $predictionClass)->getTable()));
    $predictionClass::query()->create(array_intersect_key(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2', 'home_elo' => 1500, 'away_elo' => 1500, 'predicted_spread' => 0, 'predicted_total' => 0, 'win_probability' => 0.5, 'confidence_score' => 50, 'model_version' => 'synthetic-window'], $columns));

    return $game;
}

it('selects observed MLB starts by epoch rather than venue civil split fields', function () {
    $game = scoreboardWindowGame('MLB', ['game_date' => '2026-11-25', 'game_time' => '23:00:00']);
    $game->forceFill(MlbGameStart::prepare($game, ['game_date' => '2026-11-25', 'game_time' => '23:00:00', ...MlbGameStart::observed('2026-11-26T07:00:00Z', [])]))->save();
    expect(MlbGameStart::for($game)?->toIso8601String())->toBe('2026-11-26T07:00:00+00:00');
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonPath('data.games.0.game_id', $game->id)->assertJsonPath('data.games.0.game_time', '2026-11-26T07:00:00+00:00');
});

it('retains unverified MLB calendar inventory without inventing a kickoff', function () {
    $game = scoreboardWindowGame('MLB', ['game_date' => '2026-11-26', 'game_time' => '00:00:00']);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonPath('data.games.0.game_id', $game->id)->assertJsonPath('data.games.0.game_time', null);
});

it('retains NFL date-only inventory without treating missing time as UTC midnight', function () {
    $game = scoreboardWindowGame('NFL', ['game_date' => '2026-11-26', 'game_time' => null]);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonPath('data.games.0.game_id', $game->id)->assertJsonPath('data.games.0.game_time', null);
});

it('keeps known UTC midnight distinct from local calendar-only inventory', function () {
    scoreboardWindowGame('NBA', ['game_date' => '2026-11-26', 'game_time' => '00:00:00']);
    $localMidnight = scoreboardWindowGame('NBA', ['game_date' => '2026-11-26', 'game_time' => '06:00:00']);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.game_id', $localMidnight->id)->assertJsonPath('data.games.0.game_time', '2026-11-26T06:00:00+00:00');
});

function scoreboardCanonicalWindowPrediction(string $sport, string $instant): CanonicalPrediction
{
    $start = CarbonImmutable::parse($instant);
    $event = SportEvent::factory()->create(['sport' => strtolower($sport), 'season' => 2026, 'starts_at' => LegacyTimestampCarrier::forInstant($start)]);
    $game = scoreboardWindowGame($sport, ['sport_event_id' => $event->id, 'game_date' => $start->utc()->toDateString(), 'game_time' => $start->utc()->toTimeString()]);
    if ($sport === 'MLB') {
        $game = ObservedMlbGameFixture::observe($game, $start);
    }
    $inputs = ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]];
    $run = CanonicalReadFixture::run($event->fresh(), $inputs, $sport === 'MLB' ? ObservedMlbGameFixture::snapshotAttributes($game, $inputs) : []);
    $prediction = CanonicalPrediction::factory()->create(['sport' => strtolower($sport), 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id, 'output_hash' => $run->output_hash, 'generated_at' => $run->inputSnapshot->captured_at, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

    return $prediction;
}

it('uses the same business-date window for canonical scoreboard starts without treating event wall storage as UTC', function (string $sport) {
    config(['prediction_lifecycle.canonical_reads.'.strtolower($sport) => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25T12:00:00-06:00'));
    $prediction = scoreboardCanonicalWindowPrediction($sport, '2026-11-26T06:00:00Z');
    scoreboardCanonicalWindowPrediction($sport, '2026-11-26T00:00:00Z');
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00-06:00'));
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.id', $prediction->public_id);
})->with(['NBA', 'MLB']);

it('keeps identified old NFL missing-clock inventory date-only rather than dropping it as real midnight', function () {
    $game = scoreboardWindowGame('NFL', ['game_date' => '2026-11-26', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-missing-clock', 'odds_data' => ['event_id' => 'nflverse:synthetic-missing-clock', 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]]);
    expect(NflGameStart::knownMissingImportedClock($game))->toBeTrue();
    scoreboardWindowGame('NFL', ['game_date' => '2026-11-26', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-real-midnight', 'odds_data' => ['commence_time' => '2026-11-26T00:00:00Z']]);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.game_id', $game->id)->assertJsonPath('data.games.0.game_time', null);
});

it('does not overwrite unknown current canonical kickoff with an old event-wall timestamp', function () {
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25T12:00:00-06:00'));
    $prediction = scoreboardCanonicalWindowPrediction('NFL', '2026-11-26T06:00:00Z');
    $game = $prediction->sportEvent->nflGame;
    $game->update(['game_time' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00-06:00'));
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.starts_at', null)->assertJsonPath('data.games.0.game_time', null);
});

it('excludes oversized possible missing-clock metadata from the extra calendar candidates', function () {
    scoreboardWindowGame('NFL', ['game_date' => '2026-11-26', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-oversized', 'odds_data' => ['event_id' => 'nflverse:synthetic-oversized', 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2'], 'padding' => str_repeat('x', 65_536)]]);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()->assertJsonCount(0, 'data.games');
});
