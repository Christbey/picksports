<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use Carbon\CarbonInterface;

class NflPlayoffScoringHistory
{
    public function load(int $season, CarbonInterface $cutoff, array $schedule): array
    {
        $completedScores = [];
        $needsSamples = false;
        foreach ($schedule as $game) {
            if ($game['completed']) {
                $completedScores[$game['id']] = [$game['home_score'], $game['away_score']];
            } else {
                $needsSamples = true;
            }
        }

        $historicalScores = [];
        if ($needsSamples) {
            $history = Game::query()
                ->whereBetween('season', [$season - 3, $season])
                ->where('season_type', config('nfl.season.types.regular', 2))
                ->where('status', config('nfl.statuses.final', 'STATUS_FINAL'))
                ->where('game_date', '<=', $cutoff)
                ->where('result_updated_at', '<=', $cutoff)
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->orderBy('id')
                ->get(['id', 'home_score', 'away_score']);

            foreach ($history as $game) {
                $historicalScores[$game->id] = [(int) $game->home_score, (int) $game->away_score];
            }
        }

        $touchdowns = $this->touchdowns($completedScores + $historicalScores, $cutoff);
        $samples = [];
        $tiedGames = 0;
        foreach ($historicalScores as $id => [$home, $away]) {
            $scoring = $touchdowns[$id] ?? null;
            $tied = $home === $away;
            $tiedGames += (int) $tied;
            // Decisive samples are oriented by winner. Tied samples keep their home and away sides.
            $samples[] = [
                'points' => $home >= $away ? [$home, $away] : [$away, $home],
                'touchdowns' => $home < $away && $scoring !== null ? array_reverse($scoring) : $scoring,
                'tied' => $tied,
            ];
        }

        return [
            'samples' => $samples,
            'touchdowns' => array_intersect_key($touchdowns, $completedScores),
            'tie_model' => [
                'sample_games' => count($samples),
                'sample_ties' => $tiedGames,
                'tie_probability' => $samples !== [] ? $tiedGames / count($samples) : null,
            ],
        ];
    }

    private function touchdowns(array $scores, CarbonInterface $cutoff): array
    {
        $plays = Play::query()
            ->whereIntegerInRaw('game_id', array_keys($scores))
            ->where('is_scoring_play', true)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('game_id')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get(['game_id', 'home_score', 'away_score'])
            ->groupBy('game_id');

        $touchdowns = [];
        foreach ($scores as $id => $finalScore) {
            $previous = [0, 0];
            $counts = [0, 0];
            foreach ($plays->get($id, []) as $play) {
                if ($play->home_score === null || $play->away_score === null) {
                    continue 2;
                }
                $current = [(int) $play->home_score, (int) $play->away_score];
                $homeDelta = $current[0] - $previous[0];
                $awayDelta = $current[1] - $previous[1];
                if (($homeDelta > 0 && $awayDelta > 0)
                    || ! in_array($homeDelta, [0, 1, 2, 3, 6, 7, 8], true)
                    || ! in_array($awayDelta, [0, 1, 2, 3, 6, 7, 8], true)) {
                    continue 2;
                }

                $counts[0] += (int) ($homeDelta >= 6);
                $counts[1] += (int) ($awayDelta >= 6);
                $previous = $current;
            }

            if ($previous === array_map('intval', $finalScore)) {
                $touchdowns[$id] = $counts;
            }
        }

        return $touchdowns;
    }
}
