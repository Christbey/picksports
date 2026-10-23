<?php

namespace App\Services\BettingRecommendations;

use Illuminate\Database\Eloquent\Model;

class NflPropBoardOrder
{
    /** Sort descending by freshness, evidence, then price-aware expected return. */
    public static function key(Model $prop, int $now, int $freshHours): array
    {
        $over = $prop->predicted_over_probability;
        $side = strtolower((string) $prop->recommended_side);
        $valid = is_numeric($over) && (float) $over >= 0 && (float) $over <= 100
            && in_array($side, ['over', 'under'], true);
        $probability = $valid ? ($side === 'under' ? 100 - (float) $over : (float) $over) : -1;
        $odds = $side === 'under' ? $prop->under_price : $prop->over_price;
        $priceValid = is_numeric($odds) && abs((float) $odds) >= 100;
        $profit = $priceValid ? ($odds > 0 ? $odds / 100 : 100 / abs($odds)) : 0;
        $push = max(0, min(1, (float) data_get($prop->confidence_decomposition, 'push_probability', 0) / 100));
        $return = $valid && $priceValid ? (1 - $push) * (($probability / 100) * $profit - (1 - $probability / 100)) : -1;
        $observed = $prop->fetched_at?->getTimestamp();
        $fresh = $observed !== null && $observed <= $now && $now - $observed <= $freshHours * 3600;
        $games = (int) data_get($prop->confidence_decomposition, 'history.current_season_games', 0);

        return [
            (int) $fresh,
            (int) $valid,
            (int) ($games >= 4),
            $return * max(0, min(1, (float) $prop->data_quality_score / 100)),
            $probability,
            (int) $prop->data_quality_score,
            (int) $prop->confidence_score,
            -(int) $prop->id,
        ];
    }
}
