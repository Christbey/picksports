<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Models\UserBet;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\PerformanceStatistics;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function vueCanonicalFixture(string $sport, float $homeLine = 4.5, float $homeProbability = 0.65): CanonicalPrediction
{
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addHour()]);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'season' => 2026,
        'game_date' => now()->utc()->toDateString(), 'game_time' => now()->addHour()->utc()->toTimeString(),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'status' => 'STATUS_SCHEDULED',
    ]);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded',
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'selection' => 'home', 'probability' => $homeProbability]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => $homeLine]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 45.5]);

    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

    return $prediction;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $this->withoutVite();
});
afterEach(fn () => Carbon::setTestNow());

it('shows canonical forecasts on the dashboard without legacy predictions', function (string $sport) {
    config()->set("prediction_lifecycle.canonical_reads.{$sport}", true);
    $prediction = vueCanonicalFixture($sport);
    CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $prediction->sport_event_id,
        'calculation_run_id' => null, 'phase' => 'pregame',
        'publication_state' => 'superseded', 'published_at' => now()->subMinute(), 'revision' => 2,
    ]);
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('sports', 1)->where('sports.0.name', strtoupper($sport))
        ->has('sports.0.predictions', 1)->where('sports.0.predictions.0.id', $prediction->public_id)
        ->where('sports.0.predictions.0.predicted_spread', -4.5)
        ->where('sports.0.predictions.0.win_probability', 0.65)
        ->where('stats.total_predictions_today', 1));

    $context = app(SportContextResolver::class)->resolve($sport);
    $summaries = app(SportGameQuery::class)->featuredPredictionSummaries($context);
    expect($summaries->sole()->prediction->id)->toBe($prediction->public_id)
        ->and($summaries->sole()->prediction->predictedSpread)->toBe(4.5);
    $game = $prediction->sportEvent->{$sport.'Game'};
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'home_score' => 14, 'away_score' => 7]);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()
        ->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.id', $prediction->public_id)
        ->assertJsonPath('data.games.0.predicted_spread', -4.5)
        ->assertJsonPath('data.games.0.is_live', true)->assertJsonPath('data.games.0.home_score', 14);
    $this->getJson('/api/v2/sports/'.$sport.'/games/'.$game->id)->assertOk()
        ->assertJsonPath('data.has_prediction', true);

})->with(['cfb', 'nfl', 'nba', 'cbb', 'wcbb', 'wnba', 'mlb']);

it('uses latest canonical evaluations for performance without counting revisions twice', function () {
    config()->set('prediction_lifecycle.canonical_reads.nba', true);
    $prediction = vueCanonicalFixture('nba');
    foreach ([1 => false, 2 => true] as $revision => $correct) {
        PredictionEvaluation::query()->create([
            'canonical_prediction_id' => $prediction->id, 'sport_event_id' => $prediction->sport_event_id,
            'sport' => 'nba', 'prediction_phase' => 'pregame', 'evaluation_revision' => $revision,
            'scoring_version' => 'test', 'evaluation_hash' => hash('sha256', (string) $revision),
            'actuals' => [], 'errors' => ['winner_correct' => $correct, 'spread_absolute_error' => 2.0, 'total_absolute_error' => 3.0],
            'evaluated_at' => now(),
        ]);
    }
    $stats = app(PerformanceStatistics::class);
    expect($stats->getOverallStats()['total_predictions'])->toBe(1)
        ->and($stats->getOverallStats()['winner_accuracy'])->toBe(100.0)
        ->and($stats->getSeasonToDate()['nba']['season'])->toBe(2026);
});

it('exports canonical predictions in the admin Vue page', function (string $sport) {
    config()->set("prediction_lifecycle.canonical_reads.{$sport}", true);
    $prediction = vueCanonicalFixture($sport);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['sport' => strtoupper($sport), 'tab' => 'predictions']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('predictions', 1)->where('predictions.0.id', $prediction->public_id)
        ->where('predictions.0.predicted_spread', 4.5)->has('predictionDates', 1)->has('predictionGames', 1));
})->with(['nba', 'nfl', 'mlb', 'cbb']);

it('saves and tracks canonical public IDs without mixing legacy numeric IDs', function () {
    $prediction = vueCanonicalFixture('nba');
    $user = User::factory()->create();
    UserBet::factory()->create(['user_id' => $user->id, 'prediction_id' => $prediction->id,
        'prediction_type' => Prediction::class, 'prediction_sport' => 'nba']);
    $this->actingAs($user)->postJson('/api/v2/user-bets', [
        'prediction_id' => $prediction->public_id, 'prediction_sport' => 'nba',
        'bet_amount' => 10, 'odds' => '-110', 'bet_type' => 'spread', 'selection_side' => 'home',
    ])->assertCreated()->assertJsonPath('data.prediction_id', $prediction->public_id)
        ->assertJsonPath('data.prediction_reference.event_id', $prediction->sportEvent->public_id);
    $this->getJson('/api/v2/user-bets?'.http_build_query(['prediction_id' => $prediction->public_id, 'prediction_sport' => 'nba']))
        ->assertOk()->assertJsonCount(1, 'bets.data')->assertJsonCount(1, 'tracking.user_bets');
    $this->getJson('/api/v2/user-bets?'.http_build_query(['prediction_id' => $prediction->public_id, 'prediction_sport' => 'cfb']))
        ->assertNotFound();
});

it('preserves sportsbook spread signs through the dashboard card convention', function (float $homeLine, float $probability) {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    vueCanonicalFixture('cfb', $homeLine, $probability);
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    $prediction = $response->inertiaProps('sports.0.predictions.0');

    expect((float) -$prediction['predicted_spread'])->toBe($homeLine)
        ->and((float) $prediction['model_bet_context']['model_home_spread'])->toBe($homeLine)
        ->and($prediction['win_probability'])->toBe($probability);
})->with([
    'Pitt home favorite' => [-11.7, 0.875],
    'West Virginia home underdog' => [3.1, 0.374],
    'pick em' => [0.0, 0.5],
]);
