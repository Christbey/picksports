<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\CFB\Signals\CfbFootballSignalModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function footballSignalInputs(): array
{
    $metric = ['record_season' => 2026, 'wins' => 6, 'losses' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 21, 'fpi' => 10];
    $evidence = fn ($value) => ['value' => $value, 'sample_games' => 6];

    return ['event' => ['season' => 2026, 'starts_at' => '2026-09-21T12:00:00Z', 'neutral_site' => false],
        'home' => ['elo' => 1600, 'metrics' => $metric, 'injuries' => []],
        'away' => ['elo' => 1500, 'metrics' => $metric, 'injuries' => []],
        'historical_signals' => ['home' => ['windows' => ['current_season' => ['metrics' => [
            'points_per_game' => $evidence(40), 'points_allowed_per_game' => $evidence(10)]]]],
            'away' => ['windows' => ['current_season' => ['metrics' => [
                'points_per_game' => $evidence(20), 'points_allowed_per_game' => $evidence(35)]]]]]];
}

function footballFittedEvidence(array $config, array $ids = ['scoring_pressure']): array
{
    return ['version' => CfbFootballSignalModel::VERSION, 'as_of' => '2026-09-20T00:00:00Z',
        'latest_source_observed_at' => '2026-09-19T00:00:00Z', 'status' => 'frozen_outcomes_evaluated',
        'feature_policy' => data_get($config, 'football_signals.feature_policy', 'observed_only'),
        'baseline_hash' => CfbFootballSignalEvidence::baselineHash($config),
        'catalog_hash' => CfbFootballSignalEvidence::catalogHash(CfbFootballSignalCatalog::all()),
        'joint_models' => ['spread' => ['status' => 'validated_joint_residual', 'coefficients' => array_fill_keys($ids, 2.0), 'feature_samples' => array_fill_keys($ids, 60), 'validation_games' => 18]],
        'signals' => array_fill_keys($ids, ['status' => 'validated_residual', 'coefficient' => 2.0,
            'sample_games' => 60, 'validation_games' => 18])];
}

it('does not invent effects without compatible observed evidence and does not inflate overlapping families', function () {
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    unset($config['football_signals']['weighting']);
    $inputs = footballSignalInputs();
    $capture = CarbonImmutable::parse('2026-09-20');
    $model = new CfbFootballSignalModel;
    expect($model->evaluate($inputs, $config['football_signals'], $config, $capture)['applied'])->toBe(0);
    $inputs['football_signal_evidence'] = footballFittedEvidence($config);
    $single = $model->evaluate($inputs, $config['football_signals'], $config, $capture);
    expect($single['spread_adjustment'])->toBe(2.0);
    $inputs['football_signal_evidence'] = footballFittedEvidence($config, ['scoring_pressure', 'defensive_control']);
    expect($model->evaluate($inputs, $config['football_signals'], $config, $capture)['spread_adjustment'])->toBe($single['spread_adjustment']);
    $inputs['football_signal_evidence']['catalog_hash'] = 'wrong';
    expect($model->evaluate($inputs, $config['football_signals'], $config, $capture)['applied'])->toBe(0);
    $inputs['football_signal_evidence'] = footballFittedEvidence($config);
    $different = $config;
    $different['inputs']['sample_aware_context'] = false;
    expect($model->evaluate($inputs, $config['football_signals'], $different, $capture)['applied'])->toBe(0);
    $inputs['football_signal_evidence']['as_of'] = '2026-09-20T12:00:00Z';
    expect($model->evaluate($inputs, $config['football_signals'], $config, $capture)['applied'])->toBe(0);
});

it('deduplicates games and fits only earlier dates before validating residual corrections', function () {
    $rows = [];
    foreach (range(0, 59) as $i) {
        $rows[] = ['game_id' => $i + 1, 'starts_at' => CarbonImmutable::parse('2025-08-01')->addDays($i)->toIso8601String(), 'residual' => 6.0];
    }
    $model = new CfbFootballSignalModel;
    $fit = $model->fit($rows);
    expect($fit['status'])->toBe('validated_residual')->and($fit['sample_games'])->toBe(60)
        ->and($model->fit([...$rows, ...$rows]))->toBe($fit);
    foreach ($rows as $i => &$row) {
        if ($i >= 42) {
            $row['residual'] = -6.0;
        }
    } unset($row);
    $failed = $model->fit($rows);
    expect($failed['coefficient'])->toBe($fit['coefficient'])->and($failed['status'])->toBe('no_validation_improvement');
    expect($model->fit(array_slice($rows, 0, 20))['coefficient'])->toBeNull();
});

it('changes canonical output only when a fitted condition has valid frozen support', function () {
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $inputs = footballSignalInputs();
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.6.0', 'test', 'test', 'cfb-pregame-v1', $config);
    $capture = CarbonImmutable::parse('2026-09-20');
    $calculator = app(CfbCalculator::class);
    $base = $calculator->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, $capture), $release);
    $inputs['football_signal_evidence'] = footballFittedEvidence($config);
    $supported = $calculator->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, $capture), $release);
    expect($supported->metadata['home_margin'] - $base->metadata['home_margin'])->toBe(2.0)
        ->and($supported->diagnostics['football_signals']['spread_adjustment'])->toBe(2.0)
        ->and($base->diagnostics['football_signals']['applied'])->toBe(0);
    $inputs['away']['metrics']['fpi'] = null;
    expect(app(CfbFootballSignalModel::class)->evaluate($inputs, $config['football_signals'], $config, $capture)['applied'])->toBe(0);
});

it('ignores future results and backfilled snapshots instead of claiming historical pregame evidence', function () {
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => '2026-09-01 12:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 20]);
    DB::table('cfb_games')->where('id', $game->id)->update(['created_at' => '2026-08-01', 'updated_at' => '2026-09-21']);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'schema_version' => 'cfb-pregame-v1', 'captured_at' => '2026-09-01 10:00:00', 'cutoff_at' => $event->starts_at, 'latest_source_available_at' => '2026-09-01 09:00:00',
        'inputs' => footballSignalInputs()]);
    $oldConfig = $config;
    unset($oldConfig['football_signals']);
    $oldConfig['spread']['power_rating_weight'] = 0.99;
    $release = CalculationRelease::factory()->create(['sport' => 'cfb', 'configuration' => $oldConfig]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'calculation_release_id' => $release->id, 'event_input_snapshot_id' => $snapshot->id]);
    $prediction = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb', 'phase' => 'pregame',
        'publication_state' => 'draft', 'calculation_run_id' => $run->id, 'generated_at' => '2026-09-01 10:00:00', 'published_at' => '2026-09-01 10:00:00']);
    $prediction->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -3]);
    $prediction->markets()->create(['market_type' => 'total', 'selection' => 'combined', 'projected_line' => 45]);
    $prediction->update(['publication_state' => 'published']);
    DB::table('predictions')->where('id', $prediction->id)->update(['created_at' => '2026-09-01 10:00:00']);
    DB::table('event_input_snapshots')->where('id', $snapshot->id)->update(['created_at' => '2026-09-01 10:00:00']);
    $service = new CfbFootballSignalEvidence;
    $capture = CarbonImmutable::parse('2026-09-20');
    expect($service->build($capture, $config)['prediction_ids'])->toBe([]);
    DB::table('cfb_games')->where('id', $game->id)->update(['updated_at' => '2026-09-19']);
    $evidence = $service->build($capture, $config);
    expect($evidence['prediction_ids'])->toBe([$prediction->id])->and($evidence['signals']['scoring_pressure']['sample_games'])->toBe(1)
        ->and($evidence['replayed_prediction_ids'])->toBe([$prediction->id]);
    $legacy = $config;
    unset($legacy['football_signals']['training_policy']);
    expect($service->build($capture, $legacy)['prediction_ids'])->toBe([]);
    DB::table('event_input_snapshots')->where('id', $snapshot->id)->update(['created_at' => '2026-09-02']);
    expect($service->build($capture, $config)['prediction_ids'])->toBe([]);
});

it('matches frozen evidence when database JSON reorders object keys', function () {
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $reordered = array_reverse($config, true);
    $reordered['spread'] = array_reverse($reordered['spread'], true);
    expect(CfbFootballSignalEvidence::baselineHash($config))->toBe(CfbFootballSignalEvidence::baselineHash($reordered));
    $catalog = CfbFootballSignalCatalog::all();
    $reorderedCatalog = array_reverse($catalog, true);
    $id = array_key_first($reorderedCatalog);
    $reorderedCatalog[$id] = array_reverse($reorderedCatalog[$id], true);
    expect(CfbFootballSignalEvidence::catalogHash($catalog))->toBe(CfbFootballSignalEvidence::catalogHash($reorderedCatalog));
});

it('streams the same frozen prediction order across timestamp ties without sorting large metadata or using offsets', function () {
    $at = CarbonImmutable::parse('2026-10-31 12:00:00');
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => $at->subDays(3)]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'updated_at' => $at->subDay()]);
    foreach (range(1, 67) as $revision) {
        $run = CalculationRun::factory()->create(['sport_event_id' => $event->id]);
        CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id, 'phase' => 'pregame', 'calculation_run_id' => $run->id,
            'publication_state' => $revision % 2 ? 'published' : 'superseded', 'revision' => $revision,
            'generated_at' => $revision < 60 ? $at->subDays(5) : $at->subDays(4), 'published_at' => $at->subDays(4),
            'created_at' => $at->subDays(5), 'output_metadata' => ['unused_large_payload' => str_repeat('unused', 1000)]]);
    }
    CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'publication_state' => 'published', 'revision' => 68, 'generated_at' => $at->subDays(4), 'published_at' => $at->subDays(4), 'created_at' => $at->subDays(5)]);
    $expected = CanonicalPrediction::where('sport', 'cfb')->whereNotNull('calculation_run_id')->orderByDesc('generated_at')->orderByDesc('id')->pluck('id')->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $iterator = (new ReflectionMethod(CfbFootballSignalEvidence::class, 'predictions'))->invoke(app(CfbFootballSignalEvidence::class), $at);
        $rows = collect(iterator_to_array($iterator, false));
        $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "predictions"'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($rows->pluck('id')->all())->toBe($expected)->and($rows)->toHaveCount(67)
        ->and($rows->first()->getAttributes())->not->toHaveKey('output_metadata')
        ->and($sql)->toHaveCount(2)->and($sql->implode(' '))->not->toContain('offset')->not->toContain('select * from "predictions"');
    $acceptedGames = [];
    $iterator = (new ReflectionMethod(CfbFootballSignalEvidence::class, 'predictions'))->invokeArgs(app(CfbFootballSignalEvidence::class), [$at, &$acceptedGames]);
    $visited = [];
    foreach ($iterator as $prediction) {
        $visited[] = $prediction->id;
        // A rejected newest revision must leave the older fallback eligible.
        if (count($visited) === 2) {
            $acceptedGames[$game->id] = true;
        }
    }
    expect($visited)->toBe(array_slice($expected, 0, 2));
});
