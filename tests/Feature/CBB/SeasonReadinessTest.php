<?php

use App\Actions\CBB\GeneratePrediction;
use App\Actions\CBB\GradePredictions;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Services\CBB\CbbForecastEvidence;
use App\Services\PerformanceStatistics;
use App\Services\Sports\PipelineCommandRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo('2026-11-02 12:00:00');
    Http::preventStrayRequests();
});

function cbbReadinessGame(): Game
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    return Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => 2, 'game_date' => '2026-11-03', 'game_time' => '01:00:00',
        'status' => 'STATUS_SCHEDULED', 'odds_updated_at' => now(),
        'odds_data' => ['home_team' => $home->school, 'away_team' => $away->school, 'bookmakers' => [[
            'key' => 'test', 'markets' => [
                ['key' => 'spreads', 'outcomes' => [['name' => $home->school, 'point' => -3.5, 'price' => -110], ['name' => $away->school, 'point' => 3.5, 'price' => -110]]],
                ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 140.5, 'price' => -110], ['name' => 'Under', 'point' => 140.5, 'price' => -110]]],
            ],
        ]]]]);
}

it('reports missing upcoming predictions and then honest opening-day readiness', function (): void {
    $game = cbbReadinessGame();
    expect(Artisan::call('cbb:season-readiness', ['--date' => '2026-11-02', '--json' => true]))->toBe(1);
    $before = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($before['upcoming_missing_predictions'])->toBe(1);
    app(GeneratePrediction::class)->execute($game, false);
    expect(Artisan::call('cbb:season-readiness', ['--date' => '2026-11-02', '--json' => true]))->toBe(0);
    $after = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($after['status'])->toBe('ready_with_provisional_inputs')->and($after['provisional_predictions'])->toBe(1);
});

it('does not mistake an existing final prediction for complete underlying data', function (): void {
    $game = cbbReadinessGame();
    app(GeneratePrediction::class)->execute($game, false);
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 80, 'away_score' => 70]);
    expect(Artisan::call('cbb:season-readiness', ['--date' => '2026-11-02', '--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['issues'])->toContain('unreconciled_final_box_scores')->and($report['finals_without_player_stats'])->toBe(1);
});

it('keeps actual forecasts and reconstructed forecasts in separate performance cohorts', function (): void {
    $observedGame = cbbReadinessGame();
    $observed = app(GeneratePrediction::class)->execute($observedGame, false);
    $otherGame = cbbReadinessGame();
    $this->travelTo('2026-11-03 12:00:00');
    foreach ([$observedGame, $otherGame] as $game) {
        $game->update(['status' => 'STATUS_FINAL', 'home_score' => 80, 'away_score' => 70]);
    }
    $reconstructed = app(GeneratePrediction::class)->executeHistorical($otherGame, false);
    $evidence = app(CbbForecastEvidence::class);
    expect($evidence->cohort(Prediction::query(), 'pregame')->pluck('id')->all())->toBe([$observed->id])
        ->and($evidence->cohort(Prediction::query(), 'reconstruction')->pluck('id')->all())->toBe([$reconstructed->id]);
    $observedGame->update(['odds_data' => ['bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    app(GradePredictions::class)->execute(2027);
    $stats = app(PerformanceStatistics::class)->getStatsBySport();
    expect($stats['cbb']['total_graded'])->toBe(1);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
    expect(Artisan::call('cbb:backtest-totals', ['--season' => 2027]))->toBe(0);
    expect(Artisan::output())->toContain('Evidence cohort: pregame', 'Rows: 1', '140.5')->not->toContain('999.0');
    expect(Artisan::call('cbb:backtest-spreads', ['--season' => 2027, '--cohort' => 'reconstruction']))->toBe(0);
    expect(Artisan::output())->toContain('Evidence cohort: reconstruction', 'Rows: 1');
});

it('runs the CBB pipeline in dependency order and releases its lock', function (): void {
    $commands = [];
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->andReturnUsing(function (string $command, array $arguments) use (&$commands): int {
        $commands[] = $command;
        if ($command === 'cbb:generate-predictions') {
            expect($arguments['--season'])->toBe(2027);
        }

        return 0;
    });
    $this->artisan('cbb:run-pregame-pipeline')->assertSuccessful();
    expect($commands)->toBe(['espn:sync-cbb-games-scoreboard', 'espn:sync-cbb-game-details', 'cbb:grade-predictions', 'cbb:calculate-elo', 'cbb:calculate-team-metrics', 'cbb:generate-predictions']);
    $lock = Cache::lock('cbb-pregame-pipeline', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('stops the CBB pipeline when its scoreboard dependency fails', function (): void {
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->once()->with('espn:sync-cbb-games-scoreboard', Mockery::type('array'))->andReturn(1);
    $this->artisan('cbb:run-pregame-pipeline')->assertFailed();
});

it('continues with validated fallbacks after a detail failure but returns an unhealthy pipeline status', function (): void {
    $commands = [];
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->andReturnUsing(function (string $command) use (&$commands): int {
        $commands[] = $command;

        return $command === 'espn:sync-cbb-game-details' ? 1 : 0;
    });
    $this->artisan('cbb:run-pregame-pipeline')->assertFailed();
    expect($commands)->toContain('cbb:generate-predictions');
});

it('defaults manual CBB metrics and grading to the basketball ending year', function (): void {
    $this->mock(GradePredictions::class)->shouldReceive('execute')->once()->with(2027)
        ->andReturn(['graded' => 0]);
    $this->artisan('cbb:grade-predictions')->assertSuccessful();
    Team::factory()->create();
    $this->artisan('cbb:calculate-team-metrics')->expectsOutputToContain('2027')->assertSuccessful();
});

it('does not blend stale market quotes into the CBB forecast', function (): void {
    $game = cbbReadinessGame();
    $game->update(['odds_updated_at' => now()->subHours(37)]);
    $stale = app(GeneratePrediction::class)->preview($game);
    $withoutOdds = clone $game;
    $withoutOdds->odds_data = null;
    $baseline = app(GeneratePrediction::class)->preview($withoutOdds);
    expect($stale['predicted_spread'])->toBe($baseline['predicted_spread'])->and($stale['vegas_spread'])->toBeNull();
});

it('preserves shadow generation during CBB canonical migration without abandoning the active reader', function (bool $reads, array $expected): void {
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true, 'prediction_lifecycle.canonical_reads.cbb' => $reads]);
    $generation = [];
    $this->mock(PipelineCommandRunner::class)->shouldReceive('call')->andReturnUsing(function (string $command) use (&$generation): int {
        if (str_contains($command, 'generate-')) {
            $generation[] = $command;
        }

        return 0;
    });
    $this->artisan('cbb:run-pregame-pipeline')->assertSuccessful();
    expect($generation)->toBe($expected);
})->with([[false, ['cbb:generate-predictions', 'cbb:generate-canonical-predictions']], [true, ['cbb:generate-canonical-predictions']]]);
