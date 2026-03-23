<?php

namespace App\Services\NBA;

class SpreadResidualModelTrainer
{
    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $featureColumns
     * @return array{intercept: float, coefficients: array<string, float>}
     */
    public function train(array $rows, array $featureColumns, float $ridgeLambda = 1.0): array
    {
        $samples = $this->buildSamples($rows, $featureColumns);
        $featureCount = count($featureColumns) + 1;

        $xtx = array_fill(0, $featureCount, array_fill(0, $featureCount, 0.0));
        $xty = array_fill(0, $featureCount, 0.0);

        foreach ($samples as $sample) {
            $x = $sample['x'];
            $y = $sample['y'];

            for ($i = 0; $i < $featureCount; $i++) {
                $xty[$i] += $x[$i] * $y;

                for ($j = 0; $j < $featureCount; $j++) {
                    $xtx[$i][$j] += $x[$i] * $x[$j];
                }
            }
        }

        for ($i = 1; $i < $featureCount; $i++) {
            $xtx[$i][$i] += $ridgeLambda;
        }

        $weights = $this->solveLinearSystem($xtx, $xty);

        $coefficients = [];
        foreach ($featureColumns as $index => $column) {
            $coefficients[$column] = $weights[$index + 1] ?? 0.0;
        }

        return [
            'intercept' => $weights[0] ?? 0.0,
            'coefficients' => $coefficients,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array{intercept: float, coefficients: array<string, float>}  $model
     * @return array{count: int, baseline_mae: float, challenger_mae: float, mae_delta: float}
     */
    public function evaluate(array $rows, array $model): array
    {
        $count = 0;
        $baselineErrorSum = 0.0;
        $challengerErrorSum = 0.0;

        foreach ($rows as $row) {
            $actualMargin = $this->floatValue($row['target_home_margin'] ?? null);
            $baselineSpread = $this->floatValue($row['feature_model_predicted_spread'] ?? null);

            if ($actualMargin === null || $baselineSpread === null) {
                continue;
            }

            $challengerSpread = $baselineSpread + $this->predictResidual($row, $model);

            $count++;
            $baselineErrorSum += abs($actualMargin - $baselineSpread);
            $challengerErrorSum += abs($actualMargin - $challengerSpread);
        }

        $baselineMae = $count > 0 ? $baselineErrorSum / $count : 0.0;
        $challengerMae = $count > 0 ? $challengerErrorSum / $count : 0.0;

        return [
            'count' => $count,
            'baseline_mae' => $baselineMae,
            'challenger_mae' => $challengerMae,
            'mae_delta' => $challengerMae - $baselineMae,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{intercept: float, coefficients: array<string, float>}  $model
     */
    public function predictResidual(array $row, array $model): float
    {
        $prediction = (float) ($model['intercept'] ?? 0.0);

        foreach ($model['coefficients'] ?? [] as $column => $weight) {
            $prediction += (float) $weight * ($this->floatValue($row[$column] ?? null) ?? 0.0);
        }

        return $prediction;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $featureColumns
     * @return list<array{x: list<float>, y: float}>
     */
    private function buildSamples(array $rows, array $featureColumns): array
    {
        $samples = [];

        foreach ($rows as $row) {
            $actualMargin = $this->floatValue($row['target_home_margin'] ?? null);
            $baselineSpread = $this->floatValue($row['feature_model_predicted_spread'] ?? null);

            if ($actualMargin === null || $baselineSpread === null) {
                continue;
            }

            $x = [1.0];
            foreach ($featureColumns as $column) {
                $x[] = $this->floatValue($row[$column] ?? null) ?? 0.0;
            }

            $samples[] = [
                'x' => $x,
                'y' => $actualMargin - $baselineSpread,
            ];
        }

        return $samples;
    }

    /**
     * @param  list<list<float>>  $matrix
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function solveLinearSystem(array $matrix, array $vector): array
    {
        $size = count($matrix);
        $augmented = [];

        for ($row = 0; $row < $size; $row++) {
            $augmented[$row] = [...$matrix[$row], $vector[$row]];
        }

        for ($pivot = 0; $pivot < $size; $pivot++) {
            $maxRow = $pivot;
            for ($row = $pivot + 1; $row < $size; $row++) {
                if (abs($augmented[$row][$pivot]) > abs($augmented[$maxRow][$pivot])) {
                    $maxRow = $row;
                }
            }

            if ($maxRow !== $pivot) {
                [$augmented[$pivot], $augmented[$maxRow]] = [$augmented[$maxRow], $augmented[$pivot]];
            }

            $pivotValue = $augmented[$pivot][$pivot] ?? 0.0;
            if (abs($pivotValue) < 1e-9) {
                continue;
            }

            for ($column = $pivot; $column <= $size; $column++) {
                $augmented[$pivot][$column] /= $pivotValue;
            }

            for ($row = 0; $row < $size; $row++) {
                if ($row === $pivot) {
                    continue;
                }

                $factor = $augmented[$row][$pivot] ?? 0.0;
                if (abs($factor) < 1e-12) {
                    continue;
                }

                for ($column = $pivot; $column <= $size; $column++) {
                    $augmented[$row][$column] -= $factor * $augmented[$pivot][$column];
                }
            }
        }

        return array_map(
            fn (array $row): float => (float) ($row[$size] ?? 0.0),
            $augmented
        );
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
