<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbSignalContributionGrader;
use Illuminate\Console\Scheduling\Schedule;

function signalGradePrediction(): CanonicalPrediction
{
    $definition = app(CfbCalculationReleaseDefinition::class);
    $release = new CalculationRelease(['public_id' => 'test', 'sport' => 'cfb', 'phase' => 'pregame',
        'calculator_name' => $definition->calculatorName(), 'release_type' => 'rules', 'semantic_version' => '1.5.1',
        'code_revision' => 'test', 'configuration_hash' => 'test', 'input_schema_version' => $definition->inputSchemaVersion(),
        'configuration' => $definition->configuration()]);
    $team = ['elo' => 1500, 'injuries' => [], 'metrics' => ['record_season' => 2026, 'wins' => 6, 'losses' => 0,
        'fpi' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 28, 'turnover_differential' => 0]];
    $inputs = ['event' => ['season' => 2026, 'neutral_site' => true], 'home' => $team, 'away' => $team];
    $inputs['home']['metrics']['fpi'] = 10;
    $inputs['home']['metrics']['turnover_differential'] = 5;
    $data = new EventInputSnapshotData('cfb-pregame-v1', $inputs, now()->toImmutable());
    $output = app(CfbCalculator::class)->calculate($data, CalculationReleaseData::fromModel($release));
    $snapshot = new EventInputSnapshot(['schema_version' => 'cfb-pregame-v1', 'inputs' => $inputs,
        'captured_at' => now(), 'cutoff_at' => now()->addHours(2), 'pregame_safety_status' => 'verified']);
    $run = new CalculationRun(['diagnostics' => $output->diagnostics]);
    $run->setRelation('release', $release)->setRelation('inputSnapshot', $snapshot);
    $p = new CanonicalPrediction(['generated_at' => now()]);
    $p->setRelation('calculationRun', $run)->setRelation('markets', collect($output->markets)->map(fn ($m) => new PredictionMarket([
        'market_type' => $m->marketType, 'selection' => $m->selection, 'projected_line' => $m->projectedLine,
    ])));

    return $p;
}

it('grades a signal by the exact paired prediction with and without it', function () {
    $p = signalGradePrediction();
    $original = $p->calculationRun->inputSnapshot->inputs;
    $r = app(CfbSignalContributionGrader::class)->evaluate($p, now()->addHours(2)->toImmutable(), 10, 56, -9.5, 56.5);
    $turnover = collect($r['signals'])->first(fn ($r) => $r['signal'] === 'turnovers' && $r['market'] === 'spread');
    expect($r['replay_matched'])->toBeTrue()->and($r['signal_groups_evaluated'])->toBe(9)
        ->and($turnover['full_projection'])->toBe(9.9)->and($turnover['without_signal'])->toBe(9.0)
        ->and($turnover['contribution_points'])->toBe(.9)->and($turnover['absolute_error_reduction'])->toBe(.9)
        ->and($turnover['grade'])->toBe('helped')->and($turnover['full_market_result'])->toBe('win')
        ->and($turnover['without_market_result'])->toBe('loss')
        ->and($p->calculationRun->inputSnapshot->inputs)->toBe($original);
});

it('distinguishes hurt neutral and pending grades without inventing market results', function () {
    $service = app(CfbSignalContributionGrader::class);
    $r = $service->evaluate(signalGradePrediction(), now()->addHours(2)->toImmutable(), 8, 56);
    $turnover = collect($r['signals'])->first(fn ($r) => $r['signal'] === 'turnovers' && $r['market'] === 'spread');
    expect($turnover['grade'])->toBe('hurt')->and($turnover['absolute_error_reduction'])->toBe(-.9)
        ->and($turnover['full_market_result'])->toBeNull();
    $homeField = collect($r['signals'])->first(fn ($r) => $r['signal'] === 'home_field' && $r['market'] === 'spread');
    expect($homeField['active'])->toBeFalse()->and($homeField['grade'])->toBe('neutral');
    $pending = $service->evaluate(signalGradePrediction(), now()->addHours(2)->toImmutable(), null, null);
    expect(collect($pending['signals'])->pluck('grade')->unique()->all())->toBe(['pending']);
});

it('excludes unsafe snapshots and mismatched historical calculator replays', function () {
    $service = app(CfbSignalContributionGrader::class);
    $p = signalGradePrediction();
    expect($service->evaluate($p, now()->subMinute()->toImmutable(), 10, 56)['excluded'])->toBe('no_verified_pregame_prediction');
    $p->markets->first(fn ($m) => $m->market_type === 'spread')->projected_line = -99;
    expect($service->evaluate($p, now()->addHours(2)->toImmutable(), 10, 56)['excluded'])->toBe('stored_prediction_replay_mismatch');
    $p->calculationRun->diagnostics = ['spread_baseline' => 'elo_scoring'];
    expect($service->evaluate($p, now()->addHours(2)->toImmutable(), 10, 56)['excluded'])->toBe('unsupported_fallback_baseline');
});

it('registers automatic daily grading and rejects invalid report parameters', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'cfb:report-signal-contributions'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('25 3 * * *');
    $this->artisan('cfb:report-signal-contributions', ['--season' => 'bad'])->assertFailed();
    $this->artisan('cfb:report-signal-contributions', ['--season' => 2026])->assertSuccessful();
});
