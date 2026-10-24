<?php

namespace App\Services\NFL;

use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Uses the existing NFL settlement rules, with frozen identity and no name fallback. */
class NflPropShadowGrader extends GradePlayerProps
{
    public function grade(int $season): array
    {
        $graded = $pending = 0;
        PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('blend_version', NflPropShadowService::POLICY)
            ->where('prediction_table', 'nfl_player_props')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prediction_evaluations')
                ->where('prediction_table', 'prediction_feature_snapshots')->where('blend_version', NflPropShadowService::POLICY)
                ->whereColumn('prediction_evaluations.prediction_id', 'prediction_feature_snapshots.id'))
            ->whereIn('game_id', Game::query()->select('id')->where('season', $season)->where('status', 'STATUS_FINAL'))
            ->chunkById(100, function ($snapshots) use (&$graded, &$pending): void {
                foreach ($snapshots as $snapshot) {
                    $identity = ['prediction_table' => 'prediction_feature_snapshots', 'prediction_id' => $snapshot->id,
                        'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version,
                        'blend_version' => NflPropShadowService::POLICY];
                    if (! $snapshot->pregame_safe || ! $snapshot->generated_at || ! $snapshot->game_start_at
                        || $snapshot->generated_at->gte($snapshot->game_start_at) || $snapshot->game_start_at->isFuture()
                        || ! $snapshot->features_available_at || $snapshot->features_available_at->gt($snapshot->generated_at)) {
                        throw new RuntimeException('NFL prop shadow snapshot is not a safe pregame observation.');
                    }
                    if (! hash_equals((string) $snapshot->feature_hash, NflPropShadowService::fingerprint($snapshot->features, $snapshot->outputs, $snapshot->market_context))) {
                        throw new RuntimeException('NFL prop shadow snapshot integrity check failed.');
                    }
                    $prop = new PlayerProp(['game_id' => $snapshot->game_id,
                        'player_id' => $snapshot->model_metadata['player_id'], 'market' => $snapshot->market_context['market']]);
                    $stat = $this->findPlayerStat($prop);
                    if (! $stat || (int) $stat->team_id !== (int) $snapshot->model_metadata['team_id']) {
                        $pending++;

                        continue;
                    }
                    $actual = $this->getNflActualValue($prop);
                    if ($actual === null) {
                        $pending++;

                        continue;
                    }
                    $errors = [];
                    $line = (float) $snapshot->market_context['line'];
                    foreach ($snapshot->outputs as $model => $mean) {
                        $delta = (float) $mean - $actual;
                        $errors[$model] = ['absolute_error' => abs($delta), 'squared_error' => $delta ** 2, 'bias' => $delta];
                    }
                    $evaluation = PredictionEvaluation::query()->firstOrCreate($identity, [
                        'sport' => 'nfl', 'game_id' => $snapshot->game_id,
                        'actuals' => ['value' => $actual, 'stat_id' => $stat->id, 'stat_updated_at' => $stat->updated_at?->toIso8601String()],
                        'errors' => $errors, 'market_comparison' => ['line' => $line, 'line_absolute_error' => abs($actual - $line),
                            'line_result' => $actual === $line ? 'push' : ($actual > $line ? 'over' : 'under'),
                            'recommendations_approved' => false, 'probabilities_evaluated' => false],
                        'evaluated_at' => now(),
                    ]);
                    $graded += (int) $evaluation->wasRecentlyCreated;
                }
            });

        return ['graded' => $graded, 'pending_missing_actual' => $pending];
    }

    protected function findPlayerStat(Model $prop): ?PlayerStat
    {
        return PlayerStat::query()->where('game_id', $prop->game_id)->where('player_id', $prop->player_id)->first();
    }
}
