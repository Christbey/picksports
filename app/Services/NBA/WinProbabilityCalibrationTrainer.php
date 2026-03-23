<?php

namespace App\Services\NBA;

class WinProbabilityCalibrationTrainer
{
    /**
     * @param  list<array<string, string>>  $rows
     * @return array{alpha: float, beta: float, learning_rate: float, iterations: int}
     */
    public function train(array $rows, float $learningRate = 0.01, int $iterations = 3000): array
    {
        $samples = $this->samples($rows);

        $alpha = 1.0;
        $beta = 0.0;
        $count = max(1, count($samples));

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $alphaGradient = 0.0;
            $betaGradient = 0.0;

            foreach ($samples as $sample) {
                $logit = $sample['logit'];
                $target = $sample['target'];
                $prediction = $this->sigmoid(($alpha * $logit) + $beta);
                $error = $prediction - $target;

                $alphaGradient += $error * $logit;
                $betaGradient += $error;
            }

            $alpha -= $learningRate * ($alphaGradient / $count);
            $beta -= $learningRate * ($betaGradient / $count);
        }

        return [
            'alpha' => $alpha,
            'beta' => $beta,
            'learning_rate' => $learningRate,
            'iterations' => $iterations,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array{alpha: float, beta: float}  $model
     * @return array{count: int, baseline_brier: float, challenger_brier: float, brier_delta: float, baseline_log_loss: float, challenger_log_loss: float, log_loss_delta: float, baseline_accuracy: float, challenger_accuracy: float}
     */
    public function evaluate(array $rows, array $model): array
    {
        $count = 0;
        $baselineBrier = 0.0;
        $challengerBrier = 0.0;
        $baselineLogLoss = 0.0;
        $challengerLogLoss = 0.0;
        $baselineCorrect = 0;
        $challengerCorrect = 0;

        foreach ($this->samples($rows) as $sample) {
            $baselineProbability = $sample['probability'];
            $challengerProbability = $this->predict($baselineProbability, $model);
            $target = $sample['target'];

            $count++;
            $baselineBrier += ($baselineProbability - $target) ** 2;
            $challengerBrier += ($challengerProbability - $target) ** 2;
            $baselineLogLoss += $this->logLoss($baselineProbability, $target);
            $challengerLogLoss += $this->logLoss($challengerProbability, $target);
            $baselineCorrect += ($baselineProbability >= 0.5) === ($target === 1.0) ? 1 : 0;
            $challengerCorrect += ($challengerProbability >= 0.5) === ($target === 1.0) ? 1 : 0;
        }

        $count = max(1, $count);

        return [
            'count' => $count,
            'baseline_brier' => $baselineBrier / $count,
            'challenger_brier' => $challengerBrier / $count,
            'brier_delta' => ($challengerBrier / $count) - ($baselineBrier / $count),
            'baseline_log_loss' => $baselineLogLoss / $count,
            'challenger_log_loss' => $challengerLogLoss / $count,
            'log_loss_delta' => ($challengerLogLoss / $count) - ($baselineLogLoss / $count),
            'baseline_accuracy' => $baselineCorrect / $count,
            'challenger_accuracy' => $challengerCorrect / $count,
        ];
    }

    /**
     * @param  array{alpha: float, beta: float}  $model
     */
    public function predict(float $baselineProbability, array $model): float
    {
        $probability = $this->clipProbability($baselineProbability);
        $logit = log($probability / (1.0 - $probability));

        return $this->sigmoid(($model['alpha'] * $logit) + $model['beta']);
    }

    private function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    private function logLoss(float $probability, float $target): float
    {
        $probability = $this->clipProbability($probability);

        return -(($target * log($probability)) + ((1.0 - $target) * log(1.0 - $probability)));
    }

    private function clipProbability(float $probability): float
    {
        return min(0.999999, max(0.000001, $probability));
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{probability: float, logit: float, target: float}>
     */
    private function samples(array $rows): array
    {
        $samples = [];

        foreach ($rows as $row) {
            $probability = $this->floatValue($row['feature_model_win_probability'] ?? null);
            $target = $this->targetValue($row['target_home_win'] ?? null);

            if ($probability === null || $target === null) {
                continue;
            }

            $probability = $this->clipProbability($probability);

            $samples[] = [
                'probability' => $probability,
                'logit' => log($probability / (1.0 - $probability)),
                'target' => $target,
            ];
        }

        return $samples;
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function targetValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array((string) $value, ['1', '1.0', 'true'], true) ? 1.0 : 0.0;
    }
}
