<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\TeamMetric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CbbSeasonInputs
{
    /** @return array{metric: ?TeamMetric, evidence: array<string, mixed>} */
    public function forTeam(int $teamId, Game $target): array
    {
        $current = $this->sample($teamId, (int) $target->season, $target);
        $prior = $this->sample($teamId, (int) $target->season - 1, $target);
        $currentCount = $current->count();
        $priorCount = $prior->count();
        $transitionGames = max(1, (int) config('cbb.prediction.season_inputs.transition_games', 8));
        $weight = min(1.0, $currentCount / $transitionGames);
        $source = $currentCount >= $transitionGames ? 'current_season'
            : ($currentCount > 0 ? 'blended' : ($priorCount > 0 ? 'prior_season' : 'baseline'));
        $evidence = [
            'source' => $source,
            'current_season' => (int) $target->season,
            'current_games' => $currentCount,
            'prior_season' => (int) $target->season - 1,
            'prior_games' => $priorCount,
            'current_weight' => $weight,
            'cutoff_date_exclusive' => $target->game_date->toDateString(),
            'reconciled_box_scores_only' => true,
            'provisional' => $currentCount < $transitionGames,
        ];
        if ($currentCount === 0 && $priorCount === 0) {
            return ['metric' => null, 'evidence' => $evidence];
        }

        $defaultEfficiency = (float) config('cbb.prediction.default_efficiency', 100);
        $defaultPace = (float) config('cbb.prediction.average_pace', 70);
        $priorRetention = max(0.0, min(1.0, (float) config('cbb.prediction.season_inputs.prior_retention', 0.5)));
        $values = [];
        foreach (['offensive_efficiency', 'defensive_efficiency', 'tempo'] as $field) {
            $baseline = $field === 'tempo' ? $defaultPace : $defaultEfficiency;
            $priorValue = $this->average($prior, $teamId, $field, $baseline);
            $priorValue = $baseline + $priorRetention * ($priorValue - $baseline);
            $currentValue = $this->average($current, $teamId, $field, $baseline);
            $values[$field] = round($currentValue * $weight + $priorValue * (1 - $weight), 1);
        }
        foreach (['home', 'away'] as $venue) {
            $currentVenue = $current->filter(fn (Game $game): bool => ! $game->neutral_site && ($venue === 'home' ? $game->home_team_id === $teamId : $game->away_team_id === $teamId));
            $priorVenue = $prior->filter(fn (Game $game): bool => ! $game->neutral_site && ($venue === 'home' ? $game->home_team_id === $teamId : $game->away_team_id === $teamId));
            foreach (['offensive_efficiency', 'defensive_efficiency'] as $field) {
                if ($currentVenue->isEmpty() && $priorVenue->isEmpty()) {
                    $values[$venue.'_'.$field] = null;

                    continue;
                }
                $venueWeight = min(1.0, $currentVenue->count() / $transitionGames);
                $priorValue = $defaultEfficiency + $priorRetention * ($this->average($priorVenue, $teamId, $field, $defaultEfficiency) - $defaultEfficiency);
                $values[$venue.'_'.$field] = round($venueWeight * $this->average($currentVenue, $teamId, $field, $defaultEfficiency) + (1 - $venueWeight) * $priorValue, 1);
            }
        }
        foreach (['offensive_efficiency', 'defensive_efficiency', 'tempo'] as $field) {
            $baseline = $field === 'tempo' ? $defaultPace : $defaultEfficiency;
            $priorValue = $baseline + $priorRetention * ($this->average($prior, $teamId, $field, $baseline) - $baseline);
            $rolling = $current->take(-max(1, (int) config('cbb.metrics.rolling_window_size', 10)));
            $values['rolling_'.$field] = round($weight * $this->average($rolling, $teamId, $field, $baseline) + (1 - $weight) * $priorValue, 1);
        }
        $values['net_rating'] = round($values['offensive_efficiency'] - $values['defensive_efficiency'], 1);
        $values['games_played'] = $currentCount;
        $values['meets_minimum'] = $currentCount >= $transitionGames;
        $values['team_id'] = $teamId;
        $values['season'] = (int) $target->season;

        return ['metric' => new TeamMetric($values), 'evidence' => $evidence];
    }

    private function sample(int $teamId, int $season, Game $target): Collection
    {
        $quality = app(CbbDataQuality::class);

        return $quality->reconciledGames(Game::query())->where('season', $season)
            ->whereIn('season_type', [2, 3, 'regular', 'postseason'])
            ->where('game_date', '<', $target->getRawOriginal('game_date'))
            ->where(fn (Builder $query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->with('teamStats')->orderBy('game_date')->orderBy('id')->get()
            ->filter(fn (Game $game): bool => $quality->boxScoreIssues($game, $game->teamStats) === []);
    }

    private function average(Collection $games, int $teamId, string $field, float $fallback): float
    {
        if ($games->isEmpty()) {
            return $fallback;
        }
        $points = 0.0;
        $possessions = 0.0;
        foreach ($games as $game) {
            $stat = $field === 'defensive_efficiency'
                ? $game->teamStats->first(fn ($row): bool => (int) $row->team_id !== $teamId)
                : $game->teamStats->firstWhere('team_id', $teamId);
            $points += (float) $stat->points;
            $possessions += (float) ($stat->possessions ?? ($stat->field_goals_attempted - $stat->offensive_rebounds + $stat->turnovers + (float) config('cbb.metrics.possession_coefficient', 0.4) * $stat->free_throws_attempted));
        }

        return $field === 'tempo' ? $possessions / $games->count() : 100 * $points / $possessions;
    }
}
