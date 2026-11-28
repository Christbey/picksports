<?php

namespace App\Services\MLB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Support\ScoreObservation;

/** Same home-margin convention as the actual shared grader; no fallback score observations. */
final class MlbTabularTargetEvidence
{
    public function inspect(Game $game, PredictionFeatureSnapshot $snapshot): array
    {
        $home = ScoreObservation::known($game->getRawOriginal('home_score'));
        $away = ScoreObservation::known($game->getRawOriginal('away_score'));
        $context = ['game_id' => (int) $game->id, 'status' => $game->status, 'home_score' => $home, 'away_score' => $away];
        $hash = app(MlbTabularDatasetProjector::class)->stableHash($context);
        if (! in_array($game->status, GameData::finalStatuses(), true) || $home === null || $away === null) {
            return ['qualified' => false, 'reason' => 'current_final_scores_unavailable', 'hash' => $hash];
        }
        $actuals = $snapshot->getAttributes()['evaluation_actuals'] ?? null;
        if (is_string($actuals)) {
            $actuals = MlbTabularJsonBudget::decode($actuals, MlbTabularSnapshotReader::MAX_ROW_BYTES);
        }
        if (! is_array($actuals) || ! is_numeric($actuals['actual_spread'] ?? null) || ! is_numeric($actuals['actual_total'] ?? null)
            || ! is_finite((float) $actuals['actual_spread']) || ! is_finite((float) $actuals['actual_total'])
            || (float) $actuals['actual_spread'] !== (float) ($home - $away) || (float) $actuals['actual_total'] !== (float) ($home + $away)) {
            return ['qualified' => false, 'reason' => 'current_score_target_mismatch', 'hash' => $hash];
        }
        foreach (['home_score' => $home, 'away_score' => $away] as $field => $expected) {
            if (array_key_exists($field, $actuals) && ScoreObservation::known($actuals[$field]) !== $expected) {
                return ['qualified' => false, 'reason' => 'recorded_score_target_mismatch', 'hash' => $hash];
            }
        }
        // The shared grader/evaluation projector leave the winner unknown for a tied margin.
        if ($home === $away) {
            return ['qualified' => false, 'reason' => 'winner_target_unknown', 'hash' => $hash];
        }

        return ['qualified' => true, 'reason' => null, 'hash' => $hash];
    }
}
