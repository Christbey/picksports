<?php

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Actions\NBA\GeneratePrediction;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportEvent;
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
