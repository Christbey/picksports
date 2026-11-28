<?php

namespace App\Actions\Sports;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\Predictions\LegacyPredictionGradeRecorder;
use App\Services\Predictions\PredictionEvaluationRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Model::class;

    protected const PREDICTION_TABLE = '';

    protected const GAMES_TABLE = '';

    public function execute(?int $season = null): array
    {
        $query = $this->baseUngradedFinalPredictionsQuery();

        if ($season !== null) {
            $query->where($this->gamesTable().'.season', $season);
        }

        return $this->gradePredictionsCollection($query->get());
    }

    public function executeForGame(int $gameId): array
    {
        $predictions = $this->baseUngradedFinalPredictionsQuery(true)
            ->where($this->predictionTable().'.game_id', $gameId)
            ->get();

        return $this->gradePredictionsCollection($predictions);
    }

    /**
     * @param  list<int>  $gameIds
     */
    public function executeForGameIds(array $gameIds): array
    {
        $normalizedIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($gameIds, static fn (mixed $id): bool => (int) $id > 0)
        )));

        if ($normalizedIds === []) {
            return $this->emptyResults();
        }

        $predictions = $this->baseUngradedFinalPredictionsQuery()
            ->whereIn($this->predictionTable().'.game_id', $normalizedIds)
            ->get();

        return $this->gradePredictionsCollection($predictions);
    }

    /** Explicit bounded operator repair; callers must validate scope before invoking. */
    public function repairForGameIds(array $gameIds): array
    {
        if ($gameIds === [] || count($gameIds) > 100 || array_filter($gameIds, fn ($id) => ! is_int($id) || $id < 1) !== []) {
            throw new \InvalidArgumentException('Repair requires one to 100 positive game IDs.');
        }
        $predictions = $this->baseUngradedFinalPredictionsQuery(true)
            ->whereIn($this->predictionTable().'.game_id', array_unique($gameIds))->get();

        return $this->gradePredictionsCollection($predictions, true);
    }

    protected function baseUngradedFinalPredictionsQuery(bool $includeGraded = false)
    {
        return $this->newPredictionQuery()
            ->with('game')
            ->join($this->gamesTable(), $this->predictionTable().'.game_id', '=', $this->gamesTable().'.id')
            ->whereIn($this->gamesTable().'.status', GameData::finalStatuses())
            ->whereNotNull($this->gamesTable().'.home_score')
            ->whereNotNull($this->gamesTable().'.away_score')
            ->when(! $includeGraded, fn ($query) => $query->whereNull($this->predictionTable().'.graded_at'))
            ->select($this->predictionTable().'.*', $this->gamesTable().'.home_score', $this->gamesTable().'.away_score');
    }

    protected function gradePredictionsCollection(Collection $predictions, bool $repair = false): array
    {
        $predictions = $predictions
            ->filter(fn (Model $prediction): bool => $this->shouldGradePrediction($prediction))
            ->values();

        if ($predictions->isEmpty()) {
            return $this->emptyResults();
        }

        $graded = 0;
        $winnerCorrect = 0;
        $winnerDecisions = 0;
        $spreadErrors = [];
        $totalErrors = [];

        foreach ($predictions as $prediction) {
            $outcome = $prediction->getConnection()->transaction(function () use ($prediction, $repair): ?array {
                // Serialize against score updates before locking the prediction.
                $game = $prediction->game()->getRelated()->newQuery()
                    ->lockForUpdate()->find($prediction->game_id);
                $prediction = $prediction->newQuery()->lockForUpdate()->find($prediction->getKey());
                if ($game === null || $prediction === null
                    || $prediction->game_id !== $game->getKey()
                    || ! in_array($game->status, GameData::finalStatuses(), true)
                    || $game->home_score === null || $game->away_score === null) {
                    return null;
                }
                $prediction->setRelation('game', $game);
                // WNBA's additional clock/age admission consumes these game aliases.
                $prediction->setAttribute('game_game_date', $game->game_date);
                $prediction->setAttribute('game_game_time', $game->game_time);
                $prediction->setAttribute('game_game_clock', $game->game_clock);
                if (! $this->shouldGradePrediction($prediction)) {
                    return null;
                }
                $prediction->offsetUnset('game_game_date');
                $prediction->offsetUnset('game_game_time');
                $prediction->offsetUnset('game_game_clock');
                $receipts = app(LegacyPredictionGradeRecorder::class);
                $receipts->assertUnambiguousEvaluation($prediction);
                if (! $receipts->needsGrading($prediction, $game, $repair)) {
                    return null;
                }
                $receipts->preserveBaseline($prediction, $game);

                $actualSpread = $game->home_score - $game->away_score;
                $actualTotal = $game->home_score + $game->away_score;

                $spreadError = abs($actualSpread - $prediction->predicted_spread);
                $totalError = abs($actualTotal - $prediction->predicted_total);

                $isWinnerCorrect = $actualSpread === 0
                    ? null
                    : (($actualSpread > 0 && $prediction->predicted_spread > 0)
                        || ($actualSpread < 0 && $prediction->predicted_spread < 0));

                $updates = [
                    'actual_spread' => round($actualSpread, 1),
                    'actual_total' => round($actualTotal, 1),
                    'spread_error' => round($spreadError, 1),
                    'total_error' => round($totalError, 1),
                    'winner_correct' => $isWinnerCorrect,
                    'graded_at' => now(),
                ];

                $prediction->update([
                    ...$updates,
                    ...$this->additionalGradingUpdates($prediction, $actualSpread, $actualTotal),
                ]);

                app(PredictionEvaluationRecorder::class)->record(
                    $prediction->fresh(), $game, $this->inferSportFromPrediction($prediction),
                    (float) $actualSpread, (float) $actualTotal,
                );
                $receipts->record($prediction->fresh(), $game);

                return [$isWinnerCorrect, $spreadError, $totalError];
            });
            if ($outcome === null) {
                continue;
            }
            [$isWinnerCorrect, $spreadError, $totalError] = $outcome;

            if ($isWinnerCorrect !== null) {
                $winnerDecisions++;
                if ($isWinnerCorrect) {
                    $winnerCorrect++;
                }
            }

            $spreadErrors[] = $spreadError;
            $totalErrors[] = $totalError;
            $graded++;
        }

        if ($graded === 0) {
            return $this->emptyResults();
        }

        return [
            'graded' => $graded,
            'total_games' => $graded,
            'winner_accuracy' => $winnerDecisions > 0
                ? round(($winnerCorrect / $winnerDecisions) * 100, 1)
                : 0,
            'avg_spread_error' => round(array_sum($spreadErrors) / count($spreadErrors), 2),
            'avg_total_error' => round(array_sum($totalErrors) / count($totalErrors), 2),
        ];
    }

    public function getStatsByConfidence(?int $season = null): Collection
    {
        $query = $this->newPredictionQuery()->whereNotNull('graded_at');

        if ($season !== null) {
            $query->join($this->gamesTable(), $this->predictionTable().'.game_id', '=', $this->gamesTable().'.id')
                ->where($this->gamesTable().'.season', $season)
                ->select($this->predictionTable().'.*');
        }

        return $query->get()->groupBy('confidence_score')->map(function ($predictions, $confidence) {
            $total = $predictions->count();
            $winnerDecisions = $predictions->whereNotNull('winner_correct')->count();
            $winnerCorrect = $predictions->where('winner_correct', true)->count();
            $avgSpreadError = $predictions->avg('spread_error');
            $avgTotalError = $predictions->avg('total_error');

            return [
                'confidence' => $confidence,
                'total_games' => $total,
                'winner_accuracy' => $winnerDecisions > 0
                    ? round(($winnerCorrect / $winnerDecisions) * 100, 1)
                    : 0,
                'avg_spread_error' => round($avgSpreadError, 2),
                'avg_total_error' => round($avgTotalError, 2),
            ];
        })->sortByDesc('confidence')->values();
    }

    protected function predictionTable(): string
    {
        if (static::PREDICTION_TABLE === '') {
            throw new \RuntimeException('PREDICTION_TABLE must be defined on grade predictions action.');
        }

        return static::PREDICTION_TABLE;
    }

    protected function gamesTable(): string
    {
        if (static::GAMES_TABLE === '') {
            throw new \RuntimeException('GAMES_TABLE must be defined on grade predictions action.');
        }

        return static::GAMES_TABLE;
    }

    protected function newPredictionQuery()
    {
        $predictionModel = static::PREDICTION_MODEL;
        if ($predictionModel === Model::class) {
            throw new \RuntimeException('PREDICTION_MODEL must be defined on grade predictions action.');
        }

        return $predictionModel::query();
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalGradingUpdates(Model $prediction, float $actualSpread, float $actualTotal): array
    {
        return [];
    }

    protected function shouldGradePrediction(Model $prediction): bool
    {
        return true;
    }

    private function emptyResults(): array
    {
        return [
            'graded' => 0,
            'total_games' => 0,
            'winner_accuracy' => 0,
            'avg_spread_error' => 0,
            'avg_total_error' => 0,
        ];
    }

    private function inferSportFromPrediction(Model $prediction): string
    {
        return strtolower((string) strtok($prediction->getTable(), '_'));
    }
}
