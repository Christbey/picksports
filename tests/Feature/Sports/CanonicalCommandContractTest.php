<?php

use App\Actions\CFB\EvaluateCanonicalPrediction;
use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-20 12:00:00');
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function canonicalCommandGame(string $sport, array $attributes = []): Model
{
    $context = app(SportContextResolver::class)->resolve($sport);
    $gameClass = $context->models['game'];
    $teamClass = $context->models['team'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->subDay()]);

    return $gameClass::factory()->create([
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => now()->subDay()->toDateString(),
        'game_time' => '00:00:00', 'home_score' => 80, 'away_score' => 70, 'sport_event_id' => $event->id,
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(), ...$attributes,
    ]);
}

it('preserves final filters and counts skipped and failed evaluations without stopping the remaining sport batch', function (string $sport) {
    $first = canonicalCommandGame($sport);
    $skipped = canonicalCommandGame($sport);
    $failed = canonicalCommandGame($sport);
    $last = canonicalCommandGame($sport);
    canonicalCommandGame($sport, ['status' => 'STATUS_SCHEDULED']);
    canonicalCommandGame($sport, ['home_score' => null]);
    canonicalCommandGame($sport, ['away_score' => null]);
    canonicalCommandGame($sport, ['sport_event_id' => null]);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\EvaluateCanonicalPrediction';
    $mock = $this->mock($action);
    $mock->shouldReceive('execute')->once()->withArgs(fn ($g) => $g->id === $first->id)->ordered()->andReturn(new PredictionEvaluation);
    $mock->shouldReceive('execute')->once()->withArgs(fn ($g) => $g->id === $skipped->id)->ordered()->andReturnNull();
    $mock->shouldReceive('execute')->once()->withArgs(fn ($g) => $g->id === $failed->id)->ordered()->andThrow(new RuntimeException('evaluation failed'));
    $mock->shouldReceive('execute')->once()->withArgs(fn ($g) => $g->id === $last->id)->ordered()->andReturn(new PredictionEvaluation);
    $this->artisan($sport.':evaluate-canonical-predictions', ['--season' => 2026])
        ->expectsOutput("Game {$failed->id}: evaluation failed")
        ->expectsOutput('Canonical '.strtoupper($sport).' evaluation complete: 2 evaluated, 1 skipped, 1 failed.')->assertFailed();
    expect(CanonicalPrediction::count())->toBe(0)->and(BetDecision::count())->toBe(0);
})->with(['nfl', 'cfb', 'nba', 'cbb', 'wcbb', 'mlb', 'wnba']);

it('preserves game and season scoping for every canonical evaluation command', function (string $sport) {
    $selected = canonicalCommandGame($sport);
    canonicalCommandGame($sport);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\EvaluateCanonicalPrediction';
    $this->mock($action)->shouldReceive('execute')->once()->withArgs(fn ($g) => $g->id === $selected->id)->andReturnNull();
    $this->artisan($sport.':evaluate-canonical-predictions', ['--season' => 2025, '--game' => $selected->id])
        ->expectsOutput('Canonical '.strtoupper($sport).' evaluation complete: 0 evaluated, 0 skipped, 0 failed.')->assertSuccessful();
    $this->artisan($sport.':evaluate-canonical-predictions', ['--season' => 2026, '--game' => $selected->id])
        ->expectsOutput('Canonical '.strtoupper($sport).' evaluation complete: 0 evaluated, 1 skipped, 0 failed.')->assertSuccessful();
})->with(['nfl', 'cfb', 'nba', 'cbb', 'wcbb', 'mlb', 'wnba']);

it('preserves readiness arrays unknown evidence and fail-on-not-ready behavior across all seven sports', function (string $sport) {
    $service = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\'.ucfirst($sport).'CanonicalCutoverReadinessService';
    $args = match ($sport) {
        'nfl' => [2026, null, 8],
        'cfb' => [2026, null],
        'mlb', 'wnba' => [2026, false],
        default => [2026],
    };
    $report = ['sport' => $sport, 'ready_for_cutover' => false, 'held_game_ids' => [91], 'missing_evidence' => null, 'known_zero' => 0, 'approval_hold' => true];
    $this->mock($service)->shouldReceive('report')->times(3)->with(...$args)->andReturn($report);
    $this->artisan($sport.':report-canonical-cutover-readiness', ['--season' => 2026])
        ->expectsOutputToContain('[91]')->assertSuccessful();
    $this->artisan($sport.':report-canonical-cutover-readiness', ['--season' => 2026, '--json' => true])
        ->expectsOutput(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))->assertSuccessful();
    $this->artisan($sport.':report-canonical-cutover-readiness', ['--season' => 2026, '--json' => true, '--fail-on-not-ready' => true])
        ->expectsOutputToContain('"ready_for_cutover": false')->assertFailed();
    expect(BetDecision::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
})->with(['nfl', 'cfb', 'nba', 'cbb', 'wcbb', 'mlb', 'wnba']);

it('preserves sport-specific week date horizon and offseason readiness arguments', function (string $sport, array $options, array $arguments) {
    $service = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\'.ucfirst($sport).'CanonicalCutoverReadinessService';
    $this->mock($service)->shouldReceive('report')->once()->with(...$arguments)->andReturn(['ready_for_cutover' => true]);
    $this->artisan($sport.':report-canonical-cutover-readiness', ['--season' => 2026, '--json' => true, '--fail-on-not-ready' => true, ...$options])
        ->expectsOutputToContain('"ready_for_cutover": true')->assertSuccessful();
})->with([
    ['nfl', ['--date' => '2026-10-21', '--days-forward' => 3], [2026, '2026-10-21', 3]],
    ['cfb', ['--week' => 9], [2026, 9]],
    ['mlb', ['--offseason' => true], [2026, true]],
    ['wnba', ['--offseason' => true], [2026, true]],
]);

it('retains the CFB evaluation week filter', function () {
    $selected = canonicalCommandGame('cfb', ['week' => 7]);
    canonicalCommandGame('cfb', ['week' => 8]);
    $this->mock(EvaluateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($g) => $g->id === $selected->id)->andReturn(new PredictionEvaluation);
    $this->artisan('cfb:evaluate-canonical-predictions', ['--season' => 2026, '--week' => 7])
        ->expectsOutput('Canonical CFB evaluation complete: 1 evaluated, 0 skipped, 0 failed.')->assertSuccessful();
});
