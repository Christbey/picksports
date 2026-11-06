<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use Illuminate\Support\Collection;

/** Opponent-adjusted scoring margins; no box-score or market inputs. */
class CbbScoreMarginRatings
{
    private array $samples = [];

    public function forGame(Game $target): array
    {
        $homeCourt = (float) config('cbb.prediction.home_court_points', 3.5);
        $priorWeight = max(1, (int) config('cbb.prediction.season_inputs.transition_games', 8));
        $retention = 1 - max(0.0, min(1.0, (float) config('cbb.elo.offseason_regression_factor', 0.3)));
        $cutoff = min($target->getRawOriginal('game_date'), now('UTC')->addDay()->toDateString());
        $key = json_encode([(int) $target->season, $cutoff, $homeCourt, $priorWeight, $retention]);
        if (! isset($this->samples[$key])) {
            $games = app(CbbDataQuality::class)->realGames(Game::query())
                ->whereIn('season', [(int) $target->season - 1, (int) $target->season])
                ->whereIn('season_type', [2, 3, 'regular', 'regular_season', 'postseason'])
                ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
                ->where('game_date', '<', $cutoff)->whereColumn('home_team_id', '!=', 'away_team_id')
                ->whereColumn('home_score', '!=', 'away_score')->orderBy('id')
                ->get(['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site']);
            $priorGames = $games->where('season', (int) $target->season - 1);
            $currentGames = $games->where('season', (int) $target->season);
            $prior = $this->solve($this->edges($priorGames, $homeCourt));
            $prior = array_map(fn (float $rating): float => $rating * $retention, $prior);
            $this->samples[$key] = [
                'ratings' => $this->solve($this->edges($currentGames, $homeCourt), $prior, $priorWeight),
                'prior_counts' => $this->counts($priorGames), 'current_counts' => $this->counts($currentGames),
                'source_hash' => hash('sha256', $games->toJson()), 'source_games' => $games->count(),
            ];
        }
        $sample = $this->samples[$key];
        $sides = [];
        foreach (['home' => $target->home_team_id, 'away' => $target->away_team_id] as $side => $id) {
            $sides[$side] = ['rating' => isset($sample['ratings'][$id]) ? round($sample['ratings'][$id], 6) : null,
                'prior_games' => $sample['prior_counts'][$id] ?? 0, 'current_games' => $sample['current_counts'][$id] ?? 0];
        }
        $available = $sides['home']['rating'] !== null && $sides['away']['rating'] !== null;

        return ['version' => 'score-margin-v1', 'available' => $available,
            'reason' => $available ? null : 'missing_team_score_history',
            'home_margin' => $available ? round($sides['home']['rating'] - $sides['away']['rating'] + ($target->neutral_site ? 0 : $homeCourt), 6) : null,
            ...$sides, 'prior_retention' => $retention, 'current_prior_games' => $priorWeight,
            'cutoff_date_exclusive' => $cutoff, 'source_games' => $sample['source_games'], 'source_hash' => $sample['source_hash'],
            'source' => 'completed_score_history', 'probability_validation' => 'uncalibrated'];
    }

    private function edges(Collection $games, float $homeCourt): array
    {
        $edges = [];
        foreach ($games as $game) {
            $margin = $game->home_score - $game->away_score - ($game->neutral_site ? 0 : $homeCourt);
            $edges[$game->home_team_id][] = [$game->away_team_id, $margin];
            $edges[$game->away_team_id][] = [$game->home_team_id, -$margin];
        }

        return $edges;
    }

    private function counts(Collection $games): array
    {
        $counts = [];
        foreach ($games as $game) {
            foreach ([$game->home_team_id, $game->away_team_id] as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function solve(array $edges, array $prior = [], int $priorWeight = 0): array
    {
        $ratings = $prior;
        foreach ($edges as $id => $games) {
            $ratings[$id] ??= 0.0;
        }
        for ($iteration = 0; $iteration < 200; $iteration++) {
            $next = $ratings;
            $change = 0.0;
            foreach ($edges as $id => $games) {
                $sum = $priorWeight * ($prior[$id] ?? 0.0);
                foreach ($games as [$opponent, $margin]) {
                    $sum += $margin + ($ratings[$opponent] ?? 0.0);
                }
                $next[$id] = ($ratings[$id] + $sum / ($priorWeight + count($games))) / 2;
                $change = max($change, abs($next[$id] - $ratings[$id]));
            }
            $ratings = $next;
            if ($change < 0.001) {
                break;
            }
        }

        return $ratings;
    }
}
