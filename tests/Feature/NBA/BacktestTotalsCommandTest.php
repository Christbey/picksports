<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\Predictions\ModelRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->home = Team::factory()->create();
    $this->away = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-24', 'game_time' => '22:00:00', 'home_score' => 120, 'away_score' => 115,
        'odds_data' => ['bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 999]]]]]]]]);
    $this->prediction = Prediction::factory()->create(['game_id' => $this->game->id, 'predicted_total' => 888]);
    $this->run = app(ModelRunRecorder::class)->create(sport: 'nba', runType: 'prediction', modelVersion: 'rules-v1', featureVersion: 'core-v1', blendVersion: 'baseline-v1');
    $this->mock(GeneratePrediction::class)->shouldNotReceive('preview');
});

function nbaFrozenTotalSnapshot(Game $game, Prediction $prediction, string $runId, string $generated = '2026-10-24T20:00:00Z', array $attributes = []): PredictionFeatureSnapshot
{
    $time = CarbonImmutable::parse($generated)->setTimezone(config('app.timezone'));

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'nba', 'prediction_table' => 'nba_predictions', 'prediction_id' => $prediction->id, 'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(), 'model_run_id' => $runId,
        'model_version' => 'rules-v1', 'feature_version' => 'core-v1', 'blend_version' => 'baseline-v1',
        'features' => [], 'outputs' => ['predicted_total' => 240.0], 'market_context' => ['market_total' => 230.0],
        'feature_hash' => hash('sha256', (string) Str::uuid()), 'generated_at' => $time, 'features_available_at' => $time,
        'game_start_at' => CarbonImmutable::parse('2026-10-24T22:00:00Z')->setTimezone(config('app.timezone')),
        'pregame_safe' => true, 'availability_status' => 'observed_pregame', ...$attributes,
    ]);
}

it('evaluates frozen pregame forecasts and quotes without using current metrics or mutable odds', function () {
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id);
    Artisan::call('nba:backtest-totals', ['--season' => 2027, '--detailed' => true]);
    $output = Artisan::output();
    expect($output)->toContain('Rows: 1', '240.0', '230.0', '235.0', '1-0-0')
        ->not->toContain('888.0', '999.0');
    $this->prediction->refresh();
    expect((float) $this->prediction->predicted_total)->toBe(888.0);
});

it('uses the latest usable pregame snapshot and ignores snapshots generated at or after actual tipoff', function (string $late) {
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id);
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id, $late,
        ['outputs' => ['predicted_total' => 600], 'game_start_at' => CarbonImmutable::parse('2026-10-26')->setTimezone(config('app.timezone'))]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027, '--stored' => true]);
    expect(Artisan::output())->toContain('Rows: 1', '240.0')->not->toContain('600.0');
})->with(['2026-10-24T22:00:00Z', '2026-10-24T23:00:00Z']);

it('excludes evidence with missing provenance or future features instead of rebuilding it', function (string $invalid) {
    $attributes = match ($invalid) {
        'future_features' => ['features_available_at' => CarbonImmutable::parse('2026-10-25')->setTimezone(config('app.timezone'))],
        'unknown_features' => ['features_available_at' => null],
        'unsafe' => ['pregame_safe' => false],
        'no_run' => ['model_run_id' => null],
        'no_market' => ['market_context' => []],
        'no_total' => ['outputs' => []],
    };
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id, attributes: $attributes);
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    expect(Artisan::output())->toContain('Excluded games without usable pregame evidence: 1', 'No final NBA games with usable frozen pregame totals');
})->with(['future_features', 'unknown_features', 'unsafe', 'no_run', 'no_market', 'no_total']);

it('excludes preseason by default and applies the row limit after rejecting unusable games', function () {
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id);
    Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-25', 'home_score' => 100, 'away_score' => 100]);
    Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'season' => 2027, 'season_type' => 1, 'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 100]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027, '--limit' => 1]);
    expect(Artisan::output())->toContain('Rows: 1', 'Excluded games without usable pregame evidence: 1');
});

it('does not count an exact model market tie as an under bet', function () {
    config(['nba.betting.edge_thresholds.total' => 0]);
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id, attributes: ['outputs' => ['predicted_total' => 230]]);
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    expect(Artisan::output())->toContain('Rows: 1', '0-0-0', '0 (0.0%)');
});

it('validates historical snapshots against canonical tipoff despite a contradictory raw game date', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2027,
        'starts_at' => CarbonImmutable::parse('2026-10-24T22:00:00Z')->setTimezone(config('app.timezone'))]);
    $this->game->update(['sport_event_id' => $event->id, 'game_date' => '2026-10-26']);
    nbaFrozenTotalSnapshot($this->game, $this->prediction, $this->run->id, '2026-10-24T23:00:00Z');
    Artisan::call('nba:backtest-totals', ['--season' => 2027]);
    expect(Artisan::output())->toContain('Excluded games without usable pregame evidence: 1');
});
