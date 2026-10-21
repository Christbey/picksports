<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Illuminate\Support\Collection;

/** A bounded, slate-scoped corpus; mutable prediction rows are never evidence. */
class NflCalibrationHistory
{
    private array $cache = [];

    public function clear(): void
    {
        $this->cache = [];
    }

    /** @return Collection<int, Prediction> */
    public function prior(Game $target, string $modelVersion, int $limit, string $stage = 'published'): Collection
    {
        $date = $target->game_date?->toDateString();
        if ($date === null) {
            return collect();
        }
        $capacity = max($limit, (int) config('nfl.predictions.adaptive_point_calibration.lookback_games', 384),
            (int) config('nfl.predictions.adaptive_win_probability_calibration.lookback_games', 512),
            (int) config('nfl.predictions.automated_calibration_tweaks.regime_dampener.lookback_games', 272));
        $key = $modelVersion.'|'.$date.'|'.$capacity;
        if (! isset($this->cache[$key])) {
            if (count($this->cache) >= 8) {
                array_shift($this->cache);
            }
            $eligible = PredictionFeatureSnapshot::query()
                ->where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
                ->where('model_version', $modelVersion)->where('pregame_safe', true)
                ->where('availability_status', 'observed_pregame')
                ->whereNotNull('generated_at')->whereNotNull('game_start_at')
                ->whereNotNull('features_available_at');
            $latest = (clone $eligible)->selectRaw('MAX(id)')->groupBy('game_id');
            $snapshots = PredictionFeatureSnapshot::query()
                ->select(['prediction_feature_snapshots.id', 'prediction_feature_snapshots.game_id',
                    'outputs', 'generated_at', 'features_available_at', 'game_start_at'])
                ->join('nfl_games', 'nfl_games.id', '=', 'prediction_feature_snapshots.game_id')
                ->whereIn('prediction_feature_snapshots.id', $latest)
                ->where('nfl_games.status', 'STATUS_FINAL')
                ->whereIn('nfl_games.season_type', ['2', 'regular'])
                ->whereNotNull('nfl_games.home_score')->whereNotNull('nfl_games.away_score')
                ->whereDate('nfl_games.game_date', '<', $date)
                ->orderByDesc('nfl_games.game_date')->orderByDesc('nfl_games.id')
                ->limit($capacity)->get();
            $games = Game::query()->whereIn('id', $snapshots->pluck('game_id'))
                ->with('sportEvent')->get(['id', 'sport_event_id', 'game_date', 'game_time', 'home_score', 'away_score'])->keyBy('id');
            $this->cache[$key] = $snapshots->map(function (PredictionFeatureSnapshot $snapshot) use ($games): ?Prediction {
                $game = $games->get($snapshot->game_id);
                $kickoff = $game ? app(SnapshotProvenanceResolver::class)->gameStartAt($game) : null;
                if ($kickoff === null || $snapshot->generated_at->gte($kickoff)
                    || $snapshot->features_available_at->gte($kickoff)) {
                    return null;
                }
                $outputs = $snapshot->outputs ?? [];
                foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $field) {
                    if (! is_numeric($outputs[$field] ?? null) || ! is_finite((float) $outputs[$field])) {
                        return null;
                    }
                }
                if ((float) $outputs['win_probability'] <= 0 || (float) $outputs['win_probability'] >= 1) {
                    return null;
                }
                $prediction = new Prediction(array_intersect_key($outputs, array_flip([
                    'predicted_spread', 'predicted_total', 'win_probability',
                ])));
                $prediction->setRelation('game', $game);
                $prediction->setAttribute('calibration_inputs', $outputs['calibration_inputs'] ?? []);

                return $prediction;
            })->filter()->values();
        }

        return $this->cache[$key]->map(function (Prediction $prediction) use ($stage): ?Prediction {
            if ($stage === 'published') {
                return $prediction;
            }
            $inputs = $prediction->getAttribute('calibration_inputs')[$stage] ?? [];
            $fields = $stage === 'point' ? ['predicted_spread', 'predicted_total'] : ['win_probability'];
            $row = clone $prediction;
            foreach ($fields as $field) {
                if (! is_numeric($inputs[$field] ?? null) || ! is_finite((float) $inputs[$field])) {
                    return null;
                }
                $row->setAttribute($field, $inputs[$field]);
            }

            return $row;
        })->filter()->take($limit)->values();
    }
}
