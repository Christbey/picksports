<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEventResult;
use Illuminate\Support\Facades\DB;

class CanonicalPredictionEvaluator
{
    public const SCORING_VERSION = 'canonical-v1';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function evaluate(
        CanonicalPrediction $prediction,
        SportEventResult $result,
    ): PredictionEvaluation {
        if ($prediction->sport_event_id !== $result->sport_event_id) {
            throw new PredictionLifecycleException('Prediction and result must belong to the same canonical event.');
        }

        if (! in_array($prediction->publication_state, ['published', 'superseded'], true)
            || $prediction->published_at === null) {
            throw new PredictionLifecycleException('Only published canonical prediction revisions can be evaluated.');
        }

        $prediction->loadMissing('markets');
        $metrics = $this->score($prediction, $result);
        $evaluationHash = $this->hasher->hash([
            'prediction' => $prediction->public_id,
            'prediction_output' => $prediction->output_hash,
            'result' => $result->result_hash,
            'scoring_version' => self::SCORING_VERSION,
        ]);

        return DB::transaction(function () use ($prediction, $result, $metrics, $evaluationHash): PredictionEvaluation {
            CanonicalPrediction::query()->lockForUpdate()->findOrFail($prediction->getKey());

            $existing = PredictionEvaluation::query()
                ->where('evaluation_hash', $evaluationHash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $latest = PredictionEvaluation::query()
                ->where('canonical_prediction_id', $prediction->getKey())
                ->orderByDesc('evaluation_revision')
                ->first();

            return PredictionEvaluation::query()->create([
                'canonical_prediction_id' => $prediction->getKey(),
                'sport_event_id' => $prediction->sport_event_id,
                'sport_event_result_id' => $result->getKey(),
                'evaluation_revision' => ($latest?->evaluation_revision ?? 0) + 1,
                'supersedes_prediction_evaluation_id' => $latest?->getKey(),
                'sport' => $prediction->sport,
                'prediction_phase' => $prediction->phase,
                'scoring_version' => self::SCORING_VERSION,
                'evaluation_hash' => $evaluationHash,
                'prediction_table' => null,
                'prediction_id' => null,
                'game_id' => null,
                'model_version' => $prediction->model_version,
                'feature_version' => $prediction->feature_version,
                'blend_version' => $prediction->blend_version,
                'actuals' => $metrics['actuals'],
                'errors' => $metrics['errors'],
                'market_comparison' => $metrics['market_comparison'],
                'evaluated_at' => now(),
            ]);
        });
    }

    /** @return array{actuals:array<string,mixed>,errors:array<string,mixed>,market_comparison:array<string,mixed>} */
    private function score(CanonicalPrediction $prediction, SportEventResult $result): array
    {
        $homeMoneyline = $this->market($prediction, 'moneyline', 'home');
        $homeSpread = $this->market($prediction, 'spread', 'home');
        $total = $this->market($prediction, 'total', 'combined');
        $spreadConvention = data_get($prediction->output_metadata, 'market_conventions.spread');

        if ($spreadConvention !== 'sportsbook_home_line') {
            throw new PredictionLifecycleException('Canonical evaluation requires the sportsbook home-line spread convention.');
        }

        $homeWinProbability = $this->requiredNumber($homeMoneyline->probability, 'home moneyline probability');
        $homeLine = $this->requiredNumber($homeSpread->projected_line, 'home spread line');
        $predictedTotal = $this->requiredNumber($total->projected_line, 'predicted total');

        if ($homeWinProbability <= 0 || $homeWinProbability >= 1) {
            throw new PredictionLifecycleException('Home moneyline probability must be strictly between zero and one.');
        }

        $actualHomeMargin = $result->home_score - $result->away_score;

        if ($actualHomeMargin === 0) {
            throw new PredictionLifecycleException('Canonical winner evaluation does not support tied final results.');
        }

        $actualTotal = $result->home_score + $result->away_score;
        $actualHomeWin = $actualHomeMargin > 0 ? 1.0 : 0.0;
        $predictedHomeMargin = -$homeLine;
        $spreadSignedError = $actualHomeMargin - $predictedHomeMargin;
        $totalSignedError = $actualTotal - $predictedTotal;

        return [
            'actuals' => [
                'home_score' => $result->home_score,
                'away_score' => $result->away_score,
                'home_margin' => $actualHomeMargin,
                'total_points' => $actualTotal,
                'winner' => $actualHomeWin === 1.0 ? 'home' : 'away',
            ],
            'errors' => [
                'winner_correct' => ($homeWinProbability >= 0.5) === ($actualHomeWin === 1.0),
                'brier_score' => round(($homeWinProbability - $actualHomeWin) ** 2, 8),
                'log_loss' => round(-(($actualHomeWin * log($homeWinProbability))
                    + ((1 - $actualHomeWin) * log(1 - $homeWinProbability))), 8),
                'predicted_home_margin' => round($predictedHomeMargin, 4),
                'home_margin_signed_error' => round($spreadSignedError, 4),
                'spread_absolute_error' => round(abs($spreadSignedError), 4),
                'predicted_total' => round($predictedTotal, 4),
                'total_signed_error' => round($totalSignedError, 4),
                'total_absolute_error' => round(abs($totalSignedError), 4),
            ],
            'market_comparison' => [
                'home_win_probability' => round($homeWinProbability, 6),
                'home_spread_line' => round($homeLine, 4),
                'predicted_total' => round($predictedTotal, 4),
                'spread_convention' => $spreadConvention,
            ],
        ];
    }

    private function market(CanonicalPrediction $prediction, string $type, string $selection): PredictionMarket
    {
        $markets = $prediction->markets
            ->where('market_type', $type)
            ->where('selection', $selection);

        if ($markets->count() !== 1) {
            throw new PredictionLifecycleException("Canonical evaluation requires exactly one {$type}:{$selection} market.");
        }

        return $markets->first();
    }

    private function requiredNumber(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new PredictionLifecycleException("Canonical evaluation requires a numeric {$field}.");
        }

        return (float) $value;
    }
}
