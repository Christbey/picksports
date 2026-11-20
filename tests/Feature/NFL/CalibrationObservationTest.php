<?php

use App\Application\Sports\ReadModels\NflCalibrationSample;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflCalibrationHistory;
use Illuminate\Support\Str;

it('preserves prediction decimal semantics without exposing persistence or mutable stage state', function () {
    $outputs = [
        'predicted_spread' => '2.35', 'predicted_total' => '43.25', 'win_probability' => '0.6235',
        'calibration_inputs' => [
            'point' => ['predicted_spread' => '-1.25', 'predicted_total' => '44.15'],
            'win_probability' => ['win_probability' => '0.5875'],
        ],
    ];
    $before = new Prediction($outputs);
    $game = new Game(['id' => 1, 'home_score' => 24, 'away_score' => 17]);
    $observation = NflCalibrationSample::fromOutputs($outputs, $game, ['canonical_prediction_id' => 'frozen-source']);
    $point = $observation->withValues($outputs['calibration_inputs']['point']);
    $win = $observation->withValues($outputs['calibration_inputs']['win_probability']);

    expect($observation->predicted_spread)->toBe($before->predicted_spread)
        ->and($observation->predicted_total)->toBe($before->predicted_total)
        ->and($observation->win_probability)->toBe($before->win_probability)
        ->and($point->predicted_spread)->toBe('-1.3')
        ->and($point->predicted_total)->toBe('44.2')
        ->and($win->win_probability)->toBe('0.588')
        ->and($observation->predicted_spread)->toBe('2.4')
        ->and(method_exists($observation, 'save'))->toBeFalse()
        ->and(fn () => $observation->predicted_spread = '99.0')->toThrow(Error::class)
        ->and($point->canonical_lineage)->toBe(['canonical_prediction_id' => 'frozen-source'])
        ->and($win->canonical_lineage)->toBe($observation->canonical_lineage)
        ->and(NflCalibrationSample::fromOutputs([...$outputs, 'win_probability' => 'invalid'], $game))->toBeNull();
});

it('reads immutable snapshot values and retains game identity while stage reads remain independent', function () {
    $teams = Team::factory()->count(2)->create();
    $prior = Game::factory()->create([
        'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-01', 'game_time' => '19:00:00', 'home_score' => 24, 'away_score' => 17,
    ]);
    $target = Game::factory()->create([
        'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'game_date' => '2026-09-08',
    ]);
    $prediction = Prediction::factory()->create(['game_id' => $prior->id, 'predicted_spread' => 99, 'predicted_total' => 99, 'win_probability' => .99]);
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => $prediction->id,
        'game_id' => $prior->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'immutable-test',
        'feature_version' => 'test-v1', 'blend_version' => 'baseline-v1',
        'features' => [], 'outputs' => [
            'predicted_spread' => 2.35, 'predicted_total' => 43.25, 'win_probability' => .6235,
            'calibration_inputs' => ['point' => ['predicted_spread' => -1.25, 'predicted_total' => 44.15]],
        ],
        'feature_hash' => hash('sha256', 'immutable-test'),
        'generated_at' => '2026-09-01 10:00:00', 'features_available_at' => '2026-09-01 09:00:00',
        'game_start_at' => '2026-09-01 19:00:00', 'pregame_safe' => true, 'availability_status' => 'observed_pregame',
    ]);
    $history = app(NflCalibrationHistory::class);
    $published = $history->prior($target, 'immutable-test', 10)->sole();
    $stage = $history->prior($target, 'immutable-test', 10, 'point')->sole();

    expect($published)->toBeInstanceOf(NflCalibrationSample::class)
        ->and($published->game->id)->toBe($prior->id)
        ->and($published->predicted_spread)->toBe('2.4')
        ->and($stage->predicted_spread)->toBe('-1.3')
        ->and($history->prior($target, 'immutable-test', 10)->sole()->predicted_spread)->toBe('2.4')
        ->and($history->prior($target, 'immutable-test', 10, 'absent'))->toBeEmpty()
        ->and($prediction->fresh()->predicted_spread)->toBe('99.0');
});
