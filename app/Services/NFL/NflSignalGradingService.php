<?php

namespace App\Services\NFL;

use App\Models\BetDecision;
use App\Models\NFL\Game;
use App\Models\NflSignalGrade;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Collection;

class NflSignalGradingService
{
    /**
     * @var array<int,Game|null>
     */
    private array $games = [];

    /**
     * @var array<int,Collection<int,BetDecision>>
     */
    private array $decisions = [];

    /**
     * @return array{created:int,updated:int,skipped:bool}
     */
    public function grade(NflSignalObservation $observation): array
    {
        $observation->loadMissing('featureSnapshot');
        $snapshot = $observation->featureSnapshot;
        if (! array_key_exists($observation->game_id, $this->games)) {
            $this->games[$observation->game_id] = Game::query()->find($observation->game_id);
        }

        $game = $this->games[$observation->game_id];
        $finalStatus = (string) config('nfl.statuses.final', 'STATUS_FINAL');

        if (
            $snapshot === null
            || $game === null
            || (string) $game->status !== $finalStatus
            || $game->home_score === null
            || $game->away_score === null
        ) {
            return ['created' => 0, 'updated' => 0, 'skipped' => true];
        }

        $created = 0;
        $updated = 0;
        $actualHomeMargin = (float) $game->home_score - (float) $game->away_score;
        $actualTotal = (float) $game->home_score + (float) $game->away_score;

        foreach ($this->outcomeGrades($observation, $snapshot, $actualHomeMargin, $actualTotal) as $grade) {
            $this->persistGrade($observation, $grade, $created, $updated);
        }

        if (! array_key_exists($snapshot->id, $this->decisions)) {
            $this->decisions[$snapshot->id] = BetDecision::query()
                ->with('settlement')
                ->where('sport', 'nfl')
                ->where('prediction_feature_snapshot_id', $snapshot->id)
                ->whereHas('settlement')
                ->get();
        }

        foreach ($this->decisions[$snapshot->id] as $decision) {
            $settlement = $decision->settlement;
            if ($settlement === null) {
                continue;
            }

            $resultStatus = $this->normalizeResultStatus($settlement->result_status);
            $this->persistGrade($observation, [
                'bet_decision_id' => $decision->id,
                'bet_settlement_id' => $settlement->id,
                'evaluation_key' => 'settlement:'.$decision->id,
                'evaluation_source' => 'settlement',
                'market_type' => $this->normalizeMarketType($decision->market_type, $decision->market_key),
                'direction' => $this->normalizeDirection($decision->side),
                'result_status' => $resultStatus,
                'hit' => match ($resultStatus) {
                    'win' => true,
                    'loss' => false,
                    default => null,
                },
                'line' => $this->number($decision->line),
                'actual_value' => $this->number($settlement->result_value),
                'price' => $decision->price,
                'profit_units' => $this->number($settlement->profit_units),
                'shadow_profit_units' => $this->number(data_get($settlement->metadata, 'shadow_profit_units')),
                'clv' => $this->number($settlement->clv),
                'is_actual_bet' => (bool) $decision->is_bet,
                'graded_at' => $settlement->graded_at ?? now(),
                'metadata' => [
                    'decision_hash' => $decision->decision_hash,
                    'settlement_result' => $settlement->result_status,
                    'closing_line' => $this->number($settlement->closing_line),
                    'closing_price' => $settlement->closing_price,
                    'bookmaker' => $decision->bookmaker,
                ],
            ], $created, $updated);
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => false];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function outcomeGrades(
        NflSignalObservation $observation,
        PredictionFeatureSnapshot $snapshot,
        float $actualHomeMargin,
        float $actualTotal
    ): array {
        $grades = [];
        $winProbability = $this->probability(data_get($snapshot->outputs, 'win_probability'));

        if ($winProbability !== null) {
            $actualHomeWin = $this->compare($actualHomeMargin, 0.0);
            $direction = $this->evaluationDirection($observation, 'winner', $winProbability >= 0.5 ? 'home' : 'away');
            $modelProbability = $direction === 'away' ? 1.0 - $winProbability : $winProbability;
            $baselineHomeProbability = $this->probability(
                data_get($snapshot->outputs, 'baseline_win_probability')
                    ?? data_get($snapshot->model_metadata, 'win_probability_calibration.baseline_win_probability')
            );
            $baselineProbability = $baselineHomeProbability === null
                ? null
                : ($direction === 'away' ? 1.0 - $baselineHomeProbability : $baselineHomeProbability);
            $actualProbability = $actualHomeWin === 0
                ? null
                : ($direction === ($actualHomeWin > 0 ? 'home' : 'away') ? 1.0 : 0.0);
            $brier = $actualProbability === null ? null : ($modelProbability - $actualProbability) ** 2;
            $baselineBrier = $actualProbability === null || $baselineProbability === null
                ? null
                : ($baselineProbability - $actualProbability) ** 2;

            $grades[] = [
                'evaluation_key' => 'outcome:winner',
                'evaluation_source' => 'outcome',
                'market_type' => 'winner',
                'direction' => $direction,
                'result_status' => $actualProbability === null ? 'push' : ($actualProbability === 1.0 ? 'win' : 'loss'),
                'hit' => $actualProbability === null ? null : $actualProbability === 1.0,
                'model_probability' => $modelProbability,
                'baseline_probability' => $baselineProbability,
                'actual_probability' => $actualProbability,
                'actual_value' => $actualHomeMargin,
                'absolute_error' => $actualProbability === null ? null : abs($modelProbability - $actualProbability),
                'baseline_error' => $actualProbability === null || $baselineProbability === null
                    ? null
                    : abs($baselineProbability - $actualProbability),
                'error_lift' => $actualProbability === null || $baselineProbability === null
                    ? null
                    : abs($baselineProbability - $actualProbability) - abs($modelProbability - $actualProbability),
                'brier_score' => $brier,
                'baseline_brier_score' => $baselineBrier,
                'calibration_lift' => $brier === null || $baselineBrier === null ? null : $baselineBrier - $brier,
                'is_actual_bet' => false,
                'graded_at' => now(),
                'metadata' => [
                    'home_score' => (int) round(($actualTotal + $actualHomeMargin) / 2),
                    'away_score' => (int) round(($actualTotal - $actualHomeMargin) / 2),
                    'direction_source' => $this->directionSource($observation, 'winner'),
                ],
            ];
        }

        $marketHomeMargin = $this->marketHomeMargin($snapshot);
        $predictedHomeMargin = $this->number(data_get($snapshot->outputs, 'predicted_spread'));
        if ($marketHomeMargin !== null && $predictedHomeMargin !== null) {
            $direction = $this->evaluationDirection(
                $observation,
                'spread',
                $predictedHomeMargin >= $marketHomeMargin ? 'home' : 'away'
            );
            $marketResult = $this->compare($actualHomeMargin, $marketHomeMargin);
            $hit = $marketResult === 0
                ? null
                : ($direction === ($marketResult > 0 ? 'home' : 'away'));
            $modelError = abs($actualHomeMargin - $predictedHomeMargin);
            $marketError = abs($actualHomeMargin - $marketHomeMargin);

            $grades[] = [
                'evaluation_key' => 'outcome:spread',
                'evaluation_source' => 'outcome',
                'market_type' => 'spread',
                'direction' => $direction,
                'result_status' => $hit === null ? 'push' : ($hit ? 'win' : 'loss'),
                'hit' => $hit,
                'line' => $marketHomeMargin,
                'model_value' => $predictedHomeMargin,
                'actual_value' => $actualHomeMargin,
                'absolute_error' => $modelError,
                'baseline_error' => $marketError,
                'error_lift' => $marketError - $modelError,
                'is_actual_bet' => false,
                'graded_at' => now(),
                'metadata' => [
                    'line_convention' => 'home_margin_positive_home',
                    'direction_source' => $this->directionSource($observation, 'spread'),
                ],
            ];
        }

        $marketTotal = $this->marketTotal($snapshot);
        $predictedTotal = $this->number(data_get($snapshot->outputs, 'predicted_total'));
        if ($marketTotal !== null && $predictedTotal !== null) {
            $direction = $this->evaluationDirection(
                $observation,
                'total',
                $predictedTotal >= $marketTotal ? 'over' : 'under'
            );
            $marketResult = $this->compare($actualTotal, $marketTotal);
            $hit = $marketResult === 0
                ? null
                : ($direction === ($marketResult > 0 ? 'over' : 'under'));
            $modelError = abs($actualTotal - $predictedTotal);
            $marketError = abs($actualTotal - $marketTotal);

            $grades[] = [
                'evaluation_key' => 'outcome:total',
                'evaluation_source' => 'outcome',
                'market_type' => 'total',
                'direction' => $direction,
                'result_status' => $hit === null ? 'push' : ($hit ? 'win' : 'loss'),
                'hit' => $hit,
                'line' => $marketTotal,
                'model_value' => $predictedTotal,
                'actual_value' => $actualTotal,
                'absolute_error' => $modelError,
                'baseline_error' => $marketError,
                'error_lift' => $marketError - $modelError,
                'is_actual_bet' => false,
                'graded_at' => now(),
                'metadata' => [
                    'direction_source' => $this->directionSource($observation, 'total'),
                ],
            ];
        }

        return $grades;
    }

    /**
     * @param  array<string,mixed>  $grade
     */
    private function persistGrade(
        NflSignalObservation $observation,
        array $grade,
        int &$created,
        int &$updated
    ): void {
        $record = NflSignalGrade::query()->updateOrCreate(
            [
                'nfl_signal_observation_id' => $observation->id,
                'evaluation_key' => $grade['evaluation_key'],
            ],
            $grade
        );

        if ($record->wasRecentlyCreated) {
            $created++;
        } else {
            $updated++;
        }
    }

    private function evaluationDirection(
        NflSignalObservation $observation,
        string $marketType,
        string $modelDirection
    ): string {
        if ($observation->signal_type !== 'reason_code') {
            return $modelDirection;
        }

        $compatibleDirections = match ($marketType) {
            'winner', 'spread' => ['home', 'away'],
            'total' => ['over', 'under'],
            default => [],
        };

        return in_array($observation->direction, $compatibleDirections, true)
            ? $observation->direction
            : $modelDirection;
    }

    private function directionSource(NflSignalObservation $observation, string $marketType): string
    {
        return $this->evaluationDirection($observation, $marketType, '__model__') === '__model__'
            ? 'model'
            : 'signal';
    }

    private function marketHomeMargin(PredictionFeatureSnapshot $snapshot): ?float
    {
        return $this->number(data_get($snapshot->market_context, 'market_home_margin'))
            ?? $this->number(data_get($snapshot->outputs, 'market_spread'))
            ?? $this->number(data_get($snapshot->model_metadata, 'analysis_layer.calculated_edge.market_spread'))
            ?? $this->number(data_get($snapshot->model_metadata, 'analysis_layer.pro_signal_layer.market_context.market_spread'));
    }

    private function marketTotal(PredictionFeatureSnapshot $snapshot): ?float
    {
        return $this->number(data_get($snapshot->market_context, 'market_total'))
            ?? $this->number(data_get($snapshot->outputs, 'market_total'))
            ?? $this->number(data_get($snapshot->model_metadata, 'analysis_layer.calculated_edge.market_total'))
            ?? $this->number(data_get($snapshot->model_metadata, 'analysis_layer.pro_signal_layer.market_context.market_total'));
    }

    private function normalizeMarketType(?string $marketType, ?string $marketKey = null): string
    {
        $value = strtolower(trim((string) ($marketType ?: $marketKey)));

        return match ($value) {
            'winner', 'moneyline', 'h2h' => 'winner',
            'spread', 'spreads', 'ats' => 'spread',
            'total', 'totals', 'over_under' => 'total',
            default => $value === '' ? 'unknown' : $value,
        };
    }

    private function normalizeDirection(mixed $direction): ?string
    {
        $direction = strtolower(trim((string) $direction));

        return in_array($direction, ['home', 'away', 'over', 'under'], true) ? $direction : null;
    }

    private function normalizeResultStatus(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'win', 'won' => 'win',
            'loss', 'lost' => 'loss',
            'push', 'tie', 'tied' => 'push',
            default => 'unknown',
        };
    }

    private function probability(mixed $value): ?float
    {
        $value = $this->number($value);
        if ($value === null) {
            return null;
        }

        if ($value > 1.0 && $value <= 100.0) {
            $value /= 100.0;
        }

        return max(0.0, min(1.0, $value));
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function compare(float $left, float $right): int
    {
        if (abs($left - $right) < 0.00001) {
            return 0;
        }

        return $left > $right ? 1 : -1;
    }
}
