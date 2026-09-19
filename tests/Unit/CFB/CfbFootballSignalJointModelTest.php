<?php

use App\Services\CFB\Signals\CfbFootballSignalJointModel;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;

it('fits correlated football conditions jointly and validates the capped combined prediction on later games', function () {
    $rows = [];
    foreach (range(1, 300) as $i) {
        $x = $i % 2 ? 1.0 : -1.0;
        $rows[] = ['game_id' => $i, 'starts_at' => CarbonImmutable::parse('2023-01-01')->addDays($i)->toIso8601String(),
            'features' => ['spread' => ['passing' => $x, 'protection' => $x]], 'residuals' => ['spread' => $x * 3]];
    }
    $model = new CfbFootballSignalJointModel;
    $fit = $model->fit($rows, 'spread', 2);
    expect($fit['status'])->toBe('validated_joint_residual')
        ->and($fit['coefficients']['passing'])->toBe($fit['coefficients']['protection'])
        ->and($fit['coefficients']['passing'])->toEqualWithDelta(630 / 520, 0.000001)
        ->and($fit['validation_baseline_mae'])->toBe(3.0)
        ->and($fit['validation_adjusted_mae'])->toBe(1.0)
        ->and($model->fit([...$rows, ...$rows], 'spread', 2))->toBe($fit);
    foreach ($rows as $i => &$row) {
        if ($i >= 210) {
            $row['residuals']['spread'] *= -1;
        }
    } unset($row);
    $failed = $model->fit($rows, 'spread', 2);
    expect($failed['coefficients'])->toBe($fit['coefficients'])
        ->and($failed['status'])->toBe('no_validation_improvement');
});

it('uses the same signed team and averaged total orientation as prediction contributions', function () {
    $rule = fn ($market) => ['market' => $market, 'condition' => ['all' => [['field' => 'context.neutral', 'operator' => '==', 'value' => false]]]];
    $features = CfbFootballSignalJointModel::features(['event' => ['neutral_site' => false]], ['catalog' => ['both_spread' => $rule('spread'), 'both_total' => $rule('total')]]);
    expect($features['spread']['both_spread'])->toBe(0.0)->and($features['total']['both_total'])->toBe(1.0);
});

it('preserves evidence hashes through a database-style floating point JSON round trip', function () {
    $rows = [];
    foreach (range(1, 301) as $i) {
        $rows[] = ['game_id' => $i, 'starts_at' => CarbonImmutable::parse('2023-01-01')->addDays($i)->toIso8601String(),
            'features' => ['spread' => ['trend' => 1.0]], 'residuals' => ['spread' => $i % 7 + 0.1]];
    }
    $fit = (new CfbFootballSignalJointModel)->fit($rows, 'spread', 2);
    $normalize = function ($value) use (&$normalize) {
        return is_array($value) ? array_map($normalize, $value) : (is_float($value) ? (float) sprintf('%.16g', $value) : $value);
    };
    $hasher = new CanonicalPayloadHasher;
    expect($hasher->hash($normalize($fit)))->toBe($hasher->hash($fit));
});
