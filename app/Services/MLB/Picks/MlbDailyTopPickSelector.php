<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\PickCandidate;
use Illuminate\Support\Collection;

class MlbDailyTopPickSelector
{
    /**
     * @param  Collection<int,PickCandidate>  $candidates
     * @return Collection<int,PickCandidate>
     */
    public function select(Collection $candidates, ?int $limit = null): Collection
    {
        $target = $limit ?: (int) config('mlb.picks.daily.target_count', 3);
        $minScore = (int) config('mlb.picks.daily.tracking_min_score', 58);
        $maxPerGame = (int) config('mlb.picks.daily.max_per_game', 1);
        $maxSameMarket = (int) config('mlb.picks.daily.max_same_market', 2);
        $gameCounts = [];
        $marketCounts = [];
        $selected = collect();

        foreach ($candidates->sortByDesc('score') as $candidate) {
            if ((int) $candidate->score < $minScore) {
                continue;
            }

            $gameKey = (string) $candidate->game_id;
            $marketKey = (string) $candidate->market_type;
            if (($gameCounts[$gameKey] ?? 0) >= $maxPerGame || ($marketCounts[$marketKey] ?? 0) >= $maxSameMarket) {
                continue;
            }

            $selected->push($candidate);
            $gameCounts[$gameKey] = ($gameCounts[$gameKey] ?? 0) + 1;
            $marketCounts[$marketKey] = ($marketCounts[$marketKey] ?? 0) + 1;

            if ($selected->count() >= $target) {
                break;
            }
        }

        return $selected->values();
    }
}
