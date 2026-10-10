<?php

namespace App\Services\BettingRecommendations;

use Illuminate\Database\Eloquent\Model;

class NflPropBoardOrder
{
    /** Sort descending by freshness, evidence, then probability of the selected side. */
    public static function key(Model $prop, int $now, int $freshHours): array
    {
        $over = $prop->predicted_over_probability;
        $side = strtolower((string) $prop->recommended_side);
        $valid = is_numeric($over) && (float) $over >= 0 && (float) $over <= 100
            && in_array($side, ['over', 'under'], true);
        $probability = $valid ? ($side === 'under' ? 100 - (float) $over : (float) $over) : -1;
        $observed = $prop->fetched_at?->getTimestamp();
        $fresh = $observed !== null && $observed <= $now && $now - $observed <= $freshHours * 3600;
        $games = (int) data_get($prop->confidence_decomposition, 'history.current_season_games', 0);

        return [
            (int) $fresh,
            (int) $valid,
            (int) ($games >= 4),
            $probability,
            (int) $prop->data_quality_score,
            (int) $prop->confidence_score,
            -(int) $prop->id,
        ];
    }
}
