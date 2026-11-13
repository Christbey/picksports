<?php

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Actions\NBA\GeneratePrediction;
use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Sports\PipelineCommandRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
    config(['prediction_lifecycle.canonical_pipeline.nba' => false]);
    Http::preventStrayRequests();
});

function coverageGame(array $attributes = []): Game
{
    return Game::factory()->create([
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-21', 'game_time' => '01:30:00',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        ...$attributes,
    ]);
}

it('fills late arriving and UTC next day games without overwriting existing predictions or predicting started games', function () {
    $missing = coverageGame();
    $existing = coverageGame();
    $prediction = Prediction::factory()->create(['game_id' => $existing->id, 'predicted_spread' => 4.5]);
    coverageGame(['status' => 'STATUS_IN_PROGRESS']);
    coverageGame(['status' => 'STATUS_FINAL']);
    coverageGame(['game_date' => '2026-10-20', 'game_time' => '16:00:00']);
    coverageGame(['season' => 2026]);
    coverageGame(['game_date' => '2026-11-02']);

    $this->mock(GeneratePrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($game, $narratives) => $game->id === $missing->id && $narratives === false)
        ->andReturnUsing(fn ($game) => Prediction::factory()->create(['game_id' => $game->id]));

    $this->artisan('sports:ensure-basketball-predictions nba')
        ->expectsOutput('Pregame coverage: 1 generated; 3 still missing.')->assertFailed();
    expect((float) $prediction->fresh()->predicted_spread)->toBe(4.5);
    $this->artisan('sports:ensure-basketball-predictions nba')
        ->expectsOutput('Pregame coverage: 0 generated; 3 still missing.')->assertFailed();
});

it('reports failures and continues repairing other games', function () {
    $failed = coverageGame();
    $success = coverageGame();
    $this->mock(GeneratePrediction::class)->shouldReceive('execute')->twice()
        ->andReturnUsing(function ($game) use ($failed) {
            if ($game->id === $failed->id) {
                throw new RuntimeException('Inputs unavailable');
            }

            return Prediction::factory()->create(['game_id' => $game->id]);
        });
    $this->artisan('sports:ensure-basketball-predictions nba')
        ->expectsOutputToContain('Inputs unavailable')
        ->expectsOutput('Pregame coverage: 1 generated; 1 still missing.')->assertFailed();
    expect($success->fresh()->prediction)->not->toBeNull();
});

it('fails visibly when a generator declines a game or the batch leaves uncovered games', function () {
    coverageGame();
    coverageGame();
    $this->mock(GeneratePrediction::class)->shouldReceive('execute')->once()->andReturnNull();
    $this->artisan('sports:ensure-basketball-predictions nba --limit=1')
        ->expectsOutput('Pregame coverage: 0 generated; 2 still missing.')->assertFailed();
});

it('does not skip games when saving predictions changes coverage query results', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->count(101)->create([
        'season' => 2027, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-21', 'game_time' => '01:30:00',
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
    ]);
    $this->mock(GeneratePrediction::class)->shouldReceive('execute')->times(101)
        ->andReturnUsing(fn ($game) => Prediction::factory()->create(['game_id' => $game->id]));
    $this->artisan('sports:ensure-basketball-predictions nba')
        ->expectsOutput('Pregame coverage: 101 generated; 0 still missing.')->assertSuccessful();
});

it('repairs college basketball coverage with the sport specific generator', function (string $sport) {
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $predictionClass = "App\\Models\\{$namespace}\\Prediction";
    $actionClass = "App\\Actions\\{$namespace}\\GeneratePrediction";
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => false]);
    $game = $gameClass::factory()->create([
        'season' => 2027, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-21', 'game_time' => '01:30:00',
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
    ]);
    $this->mock($actionClass)->shouldReceive('execute')->once()
        ->andReturnUsing(fn ($game) => $predictionClass::factory()->create(['game_id' => $game->id]));
    $this->artisan("sports:ensure-basketball-predictions {$sport}")
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')->assertSuccessful();
    expect($game->fresh()->prediction)->not->toBeNull();
})->with(['cbb', 'wcbb']);

it('uses published canonical coverage when the canonical pipeline is enabled', function () {
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    $event = SportEvent::factory()->create([
        'sport' => 'nba', 'season' => 2027, 'starts_at' => '2026-10-21 01:30:00',
    ]);
    $game = coverageGame(['sport_event_id' => $event->id]);
    $this->mock(GenerateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual, $publish, $trigger) => $actual->id === $game->id && $publish && $trigger === 'coverage')
        ->andReturnUsing(fn () => CanonicalPrediction::factory()->create([
            'sport_event_id' => $event->id, 'sport' => 'nba',
            'phase' => 'pregame', 'publication_state' => 'published',
        ]));
    $this->mock(GeneratePrediction::class)->shouldNotReceive('execute');
    $this->artisan('sports:ensure-basketball-predictions nba')->assertSuccessful();
    $this->artisan('sports:ensure-basketball-predictions nba')
        ->expectsOutput('Pregame coverage: 0 generated; 0 still missing.')->assertSuccessful();
});

it('updates ending year NBA ratings and metrics before refreshing seven day forecasts and preserves started and final forecasts', function () {
    $upcoming = coverageGame(['game_date' => '2026-10-27', 'game_time' => '01:00:00']);
    $existing = Prediction::factory()->create(['game_id' => $upcoming->id, 'predicted_spread' => 4.5]);
    $started = coverageGame(['game_date' => '2026-10-20', 'game_time' => '16:00:00']);
    $past = Prediction::factory()->create(['game_id' => $started->id, 'predicted_spread' => 7.5]);
    $final = coverageGame(['status' => 'STATUS_FINAL']);
    Prediction::factory()->create(['game_id' => $final->id]);
    coverageGame(['game_date' => '2026-10-29']);
    $order = [];
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->times(3)
        ->withArgs(fn ($command, $arguments) => $arguments['--season'] === 2027
            && ($command === 'nba:calculate-elo' || ($command === 'nba:calculate-team-metrics' && in_array($arguments['--season-type'], [2, 3], true))))
        ->andReturnUsing(function ($command, $arguments) use (&$order) {
            $order[] = $arguments['--season-type'] ?? 'elo';

            return 0;
        });
    $this->mock(GeneratePrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($game, $narratives) => $game->id === $upcoming->id && $narratives === false)
        ->andReturnUsing(function ($game) use (&$order, $existing) {
            expect($order)->toBe(['elo', 2, 3]);
            $existing->update(['predicted_spread' => 2.5]);

            return $existing;
        });
    $this->artisan('sports:ensure-basketball-predictions nba --days-forward=7 --refresh-existing')
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')->assertSuccessful();
    expect((float) $existing->fresh()->predicted_spread)->toBe(2.5)
        ->and((float) $past->fresh()->predicted_spread)->toBe(7.5);
});

it('does not refresh forecasts when a prerequisite fails and releases the recovery lock', function (int $failureStep) {
    coverageGame();
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->times($failureStep)->andReturn(...[...array_fill(0, $failureStep - 1, 0), 1]);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('execute');
    $this->artisan('sports:ensure-basketball-predictions nba --refresh-existing')->assertFailed();
    $lock = Cache::lock('basketball-prediction-coverage:nba', 600);
    expect($lock->get())->toBeTrue();
    $lock->release();
})->with([1, 2, 3]);

it('rejects NBA recovery flags for college basketball', function () {
    $this->artisan('sports:ensure-basketball-predictions cbb --refresh-existing')->assertFailed();
});

it('schedules missing NBA and CBB coverage across the full readiness horizon without refreshing existing forecasts', function (string $sport) {
    $event = collect(app(Schedule::class)->events())
        ->firstWhere('description', strtoupper($sport).': Ensure Pregame Prediction Coverage');
    expect($event)->not->toBeNull()->and($event->command)->toContain("sports:ensure-basketball-predictions {$sport} --days-forward=8")
        ->and($event->command)->not->toContain('--refresh-existing')->and($event->expression)->toBe('*/5 * * * *');
})->with(['nba', 'cbb']);

it('fills eighth-day NBA and CBB forecasts once while retaining narrower explicit windows', function (string $sport) {
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $gameClass = $context->models['game'];
    $teamClass = $context->models['team'];
    $tip = now()->addDays(8)->startOfDay()->addHours(20);
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2027, 'starts_at' => $tip]);
    $utc = $event->fresh()->starts_at->utc();
    $game = $gameClass::factory()->create(['season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'sport_event_id' => $event->id, 'game_date' => $utc->toDateString(), 'game_time' => $utc->toTimeString(),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GenerateCanonicalPrediction';
    $this->mock($action)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual, $publish, $trigger) => $actual->id === $game->id && $publish && $trigger === 'coverage')
        ->andReturnUsing(fn () => CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id, 'phase' => 'pregame', 'publication_state' => 'published']));
    $this->mock(PipelineCommandRunner::class)->shouldNotReceive('call');
    $this->artisan('sports:ensure-basketball-predictions', ['sport' => $sport, '--days-forward' => $sport === 'nba' ? 7 : 2])
        ->expectsOutput('Pregame coverage: 0 generated; 0 still missing.')->assertSuccessful();
    $this->artisan('sports:ensure-basketball-predictions', ['sport' => $sport, '--days-forward' => 8])
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')->assertSuccessful();
    $this->artisan('sports:ensure-basketball-predictions', ['sport' => $sport, '--days-forward' => 8])
        ->expectsOutput('Pregame coverage: 0 generated; 0 still missing.')->assertSuccessful();
    expect(CanonicalPrediction::where('sport', $sport)->count())->toBe(1)->and(BetDecision::count())->toBe(0);
})->with(['nba', 'cbb']);

it('reports CBB placeholder holds without failing or using the generation batch', function () {
    config(['prediction_lifecycle.canonical_pipeline.cbb' => false]);
    $placeholder = App\Models\CBB\Team::factory()->create(['school' => 'TBD', 'abbreviation' => 'TBD', 'espn_id' => '-1']);
    $home = App\Models\CBB\Team::factory()->create();
    $away = App\Models\CBB\Team::factory()->create();
    $attributes = [
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-21', 'game_time' => '01:30:00', 'home_team_id' => $home->id,
    ];
    $held = App\Models\CBB\Game::factory()->create([...$attributes, 'away_team_id' => $placeholder->id]);
    $eligible = App\Models\CBB\Game::factory()->create([...$attributes, 'away_team_id' => $away->id]);
    $this->mock(App\Actions\CBB\GeneratePrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($game) => $game->id === $eligible->id)
        ->andReturnUsing(fn ($game) => App\Models\CBB\Prediction::factory()->create(['game_id' => $game->id]));

    $this->artisan('sports:ensure-basketball-predictions cbb --limit=1')
        ->expectsOutput("CBB placeholder matchups held: {$held->id}.")
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')
        ->assertSuccessful();
});

it('records a college missed tipoff once while retrying a game with a recoverable input hold', function (string $sport) {
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $predictionClass = "App\\Models\\{$namespace}\\Prediction";
    $actionClass = "App\\Actions\\{$namespace}\\GeneratePrediction";
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => false]);
    $teams = ['home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()];
    $missed = $gameClass::factory()->create([
        ...$teams, 'season' => 2027, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-20', 'game_time' => '16:00:00',
    ]);
    $upcoming = $gameClass::factory()->create([
        ...$teams, 'season' => 2027, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-21', 'game_time' => '',
    ]);
    $this->mock($actionClass)->shouldReceive('execute')->once()
        ->withArgs(fn ($game, $narratives) => $game->id === $upcoming->id && $narratives === false)
        ->andReturnUsing(fn ($game) => $predictionClass::factory()->create(['game_id' => $game->id]));

    $this->artisan("sports:ensure-basketball-predictions {$sport}")
        ->expectsOutput("{$namespace} coverage: 1 newly missed tipoff; 1 pending inputs; 0 held for unusable metrics; 0 deferred by batch limit.")
        ->assertSuccessful();
    $upcoming->update(['game_time' => '01:30:00']);
    $this->artisan("sports:ensure-basketball-predictions {$sport}")
        ->expectsOutput("{$namespace} coverage: 0 newly missed tipoff; 0 pending inputs; 0 held for unusable metrics; 0 deferred by batch limit.")
        ->expectsOutput('Pregame coverage: 1 generated; 0 still missing.')->assertSuccessful();
    expect($missed->prediction()->exists())->toBeFalse();
})->with(['cbb', 'wcbb']);
