<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\LiveShadowEvidenceEvaluator;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses()->group('ml', 'shadow');

afterEach(function () {
    Carbon::setTestNow();
});

it('selects one active challenger alongside the latest promoted champion', function () {
    Carbon::setTestNow('2026-08-03 06:40:00');
    $first = shadowSelectorArtifact('mlb', 'mlb_tabular_bundle', 'challenger');
    $second = shadowSelectorArtifact('mlb', 'mlb_tabular_bundle', 'promotion_eligible');
    $champion = shadowSelectorArtifact('mlb', 'mlb_tabular_bundle', 'promoted');
    $selector = app(ShadowArtifactSelector::class);

    $selector->activateChallenger($first, ['source' => 'weekly_training']);
    expect($selector->activeChallenger('mlb', 'mlb_tabular_bundle')?->is($first))->toBeTrue()
        ->and(data_get($first->metrics, 'shadow_selection.metadata.source'))->toBe('weekly_training');

    $selector->activateChallenger($second, ['lifecycle_run_id' => 'run-2']);
    $cohort = $selector->inferenceCohort('mlb', 'mlb_tabular_bundle');

    expect($selector->activeChallenger('mlb', 'mlb_tabular_bundle')?->is($second))->toBeTrue()
        ->and(data_get($first->refresh()->metrics, 'shadow_selection.active'))->toBeFalse()
        ->and(data_get($first->metrics, 'shadow_selection.deactivation_reason'))
        ->toBe('superseded_by_'.$second->id)
        ->and($cohort->pluck('id')->all())->toBe([$second->id, $champion->id])
        ->and($selector->inferenceCohort('mlb', 'mlb_tabular_bundle', $first->id)
            ->pluck('id')->all())->toBe([$first->id]);

    $selector->deactivateChallenger($second, 'evidence_window_complete');

    expect($selector->activeChallenger('mlb', 'mlb_tabular_bundle'))->toBeNull()
        ->and(data_get($second->metrics, 'shadow_selection.deactivation_reason'))
        ->toBe('evidence_window_complete')
        ->and($selector->inferenceCohort('mlb', 'mlb_tabular_bundle')->pluck('id')->all())
        ->toBe([$champion->id]);
});

it('counts promotion evidence by distinct games instead of repeated snapshots', function () {
    $artifact = shadowSelectorArtifact('nfl', 'nfl_tabular_bundle', 'challenger');
    $inferenceRun = app(ModelRunRecorder::class)->create(
        sport: 'nfl',
        runType: 'shadow_inference',
        modelVersion: $artifact->model_version,
        featureVersion: $artifact->feature_version,
        blendVersion: 'shadow-inference-v1',
        status: 'completed',
        completedAt: now(),
    );
    $gameStart = now()->addDay();

    foreach ([1, 2] as $sequence) {
        $snapshot = PredictionFeatureSnapshot::query()->create([
            'sport' => 'nfl',
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => 7000 + $sequence,
            'game_id' => 7000,
            'snapshot_run_id' => (string) Str::uuid(),
            'model_run_id' => $inferenceRun->id,
            'model_version' => 'rules-v1',
            'feature_version' => 'nfl-pregame-ml-v3',
            'blend_version' => 'baseline-v1',
            'features' => [],
            'outputs' => [],
            'feature_hash' => hash('sha256', 'repeated-game-'.$sequence),
            'generated_at' => now()->addMinutes($sequence),
            'game_start_at' => $gameStart,
            'features_available_at' => now()->addMinutes($sequence),
            'pregame_safe' => true,
            'availability_status' => 'observed_pregame',
        ]);
        $shadow = ShadowModelOutput::query()->create([
            'inference_run_id' => $inferenceRun->id,
            'model_artifact_id' => $artifact->id,
            'prediction_feature_snapshot_id' => $snapshot->id,
            'sport' => 'nfl',
            'game_table' => 'nfl_games',
            'game_id' => 7000,
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => 7000 + $sequence,
            'market_type' => 'win_probability',
            'baseline_output' => 0.50,
            'challenger_output' => 0.60,
            'output_delta' => 0.10,
            'status' => 'shadow',
            'generated_at' => now()->addMinutes($sequence),
        ]);
        $decision = BetDecision::query()->create([
            'decision_run_id' => (string) Str::uuid(),
            'model_run_id' => $inferenceRun->id,
            'model_artifact_id' => $artifact->id,
            'shadow_model_output_id' => $shadow->id,
            'prediction_feature_snapshot_id' => $snapshot->id,
            'sport' => 'nfl',
            'game_table' => 'nfl_games',
            'game_id' => 7000,
            'prediction_table' => 'nfl_predictions',
            'prediction_id' => 7000 + $sequence,
            'market_type' => 'moneyline',
            'market_key' => 'h2h',
            'side' => 'home',
            'status' => 'shadow_no_bet',
            'is_public' => false,
            'is_tracking_only' => true,
            'is_bet' => false,
            'pregame_safe' => true,
            'decided_at' => now()->addMinutes($sequence),
            'game_start_at' => $gameStart,
            'decision_hash' => hash('sha256', 'repeated-decision-'.$sequence),
        ]);
        BetSettlement::query()->create([
            'bet_decision_id' => $decision->id,
            'result_status' => 'win',
            'profit_units' => 1,
            'graded_at' => now()->addDays(2),
            'settled_at' => now()->addDays(2),
        ]);
    }

    $evidence = app(LiveShadowEvidenceEvaluator::class)->evaluate(
        $artifact,
        ['win_probability'],
        [
            'minimum_live_shadow_observations' => 2,
            'minimum_settled_shadow_decisions' => 2,
        ],
    );

    expect($evidence['passed'])->toBeFalse()
        ->and(data_get($evidence, 'markets.win_probability.live_pregame_safe_observations'))->toBe(1)
        ->and(data_get($evidence, 'markets.win_probability.settled_pregame_safe_decisions'))->toBe(1);
});

function shadowSelectorArtifact(string $sport, string $modelType, string $status): ModelArtifact
{
    $id = (string) Str::uuid();
    $run = app(ModelRunRecorder::class)->create(
        sport: $sport,
        runType: 'training',
        modelVersion: $sport.'-selector-v1',
        featureVersion: $sport.'-pregame-ml-v1',
        blendVersion: 'tabular-v1',
        status: 'completed',
        completedAt: now()->subDay(),
    );

    return ModelArtifact::query()->create([
        'id' => $id,
        'training_run_id' => $run->id,
        'sport' => $sport,
        'market_type' => 'multi_market',
        'model_type' => $modelType,
        'model_version' => $sport.'-selector-v1',
        'feature_version' => $sport.'-pregame-ml-v1',
        'dataset_hash' => hash('sha256', 'dataset-'.$id),
        'artifact_path' => storage_path('app/ml/tests/'.$id.'.zip'),
        'artifact_hash' => hash('sha256', 'artifact-'.$id),
        'status' => $status,
        'promotion_decision' => $status === 'promoted'
            ? ['promoted_markets' => ['win_probability', 'spread', 'total']]
            : null,
        'promoted_at' => $status === 'promoted' ? now()->subHour() : null,
    ]);
}
