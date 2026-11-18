<?php

namespace App\Services\NFL;

use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/** Uses the existing NFL settlement rules, with frozen identity and no name fallback. */
class NflPropShadowGrader extends GradePlayerProps
{
    public function grade(int $season): array
    {
        $graded = $pending = 0;
        $invalid = [];
        PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('blend_version', NflPropShadowService::POLICY)
            ->where('prediction_table', 'nfl_player_props')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('prediction_evaluations')
                ->where('prediction_table', 'prediction_feature_snapshots')->where('blend_version', NflPropShadowService::POLICY)
                ->whereColumn('prediction_evaluations.prediction_id', 'prediction_feature_snapshots.id'))
            ->whereIn('game_id', Game::query()->select('id')->where('season', $season)->where('status', 'STATUS_FINAL'))
            ->chunkById(100, function ($snapshots) use (&$graded, &$pending, &$invalid): void {
                foreach ($snapshots as $snapshot) {
                    try {
                        $features = $snapshot->features;
                        $outputs = $snapshot->outputs;
                        $market = $snapshot->market_context;
                        $metadata = $snapshot->model_metadata;
                    } catch (\RuntimeException|\JsonException) {
                        $invalid[$snapshot->id] = 'invalid_snapshot_payload';

                        continue;
                    }
                    if (! is_array($features) || ! is_array($outputs)
                        || ! is_array($market) || ! is_array($metadata)
                        || ! $this->validGradingPayload($outputs, $market, $metadata)) {
                        $invalid[$snapshot->id] = 'invalid_snapshot_payload';

                        continue;
                    }
                    $identity = ['prediction_table' => 'prediction_feature_snapshots', 'prediction_id' => $snapshot->id,
                        'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version,
                        'blend_version' => NflPropShadowService::POLICY];
                    if (! $snapshot->pregame_safe || ! $snapshot->generated_at || ! $snapshot->game_start_at
                        || $snapshot->generated_at->gte($snapshot->game_start_at) || $snapshot->game_start_at->isFuture()
                        || ! $snapshot->features_available_at || $snapshot->features_available_at->gt($snapshot->generated_at)) {
                        $invalid[$snapshot->id] = 'unsafe_pregame_observation';

                        continue;
                    }
                    $version = $metadata['fingerprint_version'] ?? NflPropShadowService::LEGACY_FINGERPRINT_VERSION;
                    if (! in_array($version, [NflPropShadowService::LEGACY_FINGERPRINT_VERSION, NflPropShadowService::FINGERPRINT_VERSION], true)) {
                        $invalid[$snapshot->id] = 'unknown_fingerprint_version';

                        continue;
                    }
                    try {
                        $fingerprint = NflPropShadowService::fingerprint($features, $outputs, $market, $version);
                    } catch (\InvalidArgumentException) {
                        $invalid[$snapshot->id] = 'snapshot_numeric_resolution_mismatch';

                        continue;
                    }
                    if (! hash_equals((string) $snapshot->feature_hash, $fingerprint)) {
                        $invalid[$snapshot->id] = 'snapshot_integrity_mismatch';

                        continue;
                    }
                    $prop = new PlayerProp(['game_id' => $snapshot->game_id,
                        'player_id' => $metadata['player_id'], 'market' => $market['market']]);
                    $stat = $this->findPlayerStat($prop);
                    if (! $stat || (int) $stat->team_id !== (int) $metadata['team_id']) {
                        $pending++;

                        continue;
                    }
                    $actual = $this->getNflActualValue($prop);
                    if ($actual === null || ! is_finite($actual)) {
                        $pending++;

                        continue;
                    }
                    $errors = [];
                    $line = (float) $market['line'];
                    foreach ($outputs as $model => $mean) {
                        $delta = (float) $mean - $actual;
                        $squared = $delta ** 2;
                        if (! is_finite($delta) || ! is_finite($squared)) {
                            $invalid[$snapshot->id] = 'invalid_snapshot_payload';

                            continue 2;
                        }
                        $errors[$model] = ['absolute_error' => abs($delta), 'squared_error' => $squared, 'bias' => $delta];
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

        if ($invalid !== []) {
            Log::error('NFL prop shadow grading rejected invalid immutable snapshots.', ['season' => $season, 'invalid_snapshots' => $invalid]);
        }

        return ['graded' => $graded, 'pending_missing_actual' => $pending, 'invalid_snapshot_count' => count($invalid), 'invalid_snapshots' => $invalid];
    }

    protected function findPlayerStat(Model $prop): ?PlayerStat
    {
        return PlayerStat::query()->where('game_id', $prop->game_id)->where('player_id', $prop->player_id)->first();
    }

    private function validGradingPayload(array $outputs, array $market, array $metadata): bool
    {
        foreach (['player_id', 'team_id'] as $field) {
            $value = $metadata[$field] ?? null;
            if ((! is_int($value) && ! is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return false;
            }
        }
        if (! in_array($market['market'] ?? null, [
            'player_pass_yds', 'player_pass_attempts', 'player_pass_completions', 'player_pass_tds',
            'player_pass_interceptions', 'player_rush_yds', 'player_rush_attempts', 'player_receptions',
            'player_reception_yds', 'player_anytime_td',
        ], true) || ! is_numeric($market['line'] ?? null) || ! is_finite((float) $market['line']) || $outputs === []) {
            return false;
        }
        foreach ($outputs as $model => $mean) {
            if (! is_string($model) || trim($model) === '' || ! is_numeric($mean) || ! is_finite((float) $mean)) {
                return false;
            }
        }

        return true;
    }
}
