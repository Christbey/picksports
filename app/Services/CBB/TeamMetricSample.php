<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Support\Sports\CbbSeasonTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

class TeamMetricSample
{
    public function __construct(private CbbDataQuality $quality) {}

    public function forTeam(Model $team, int $season, int|string|null $seasonType = null): Collection
    {
        return $this->quality->reconciledGames(Game::query())
            ->where('season', $season)
            ->whereIn('season_type', CbbSeasonTypes::candidates($seasonType))
            ->where(fn (Builder $query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->with(['teamStats', 'homeTeam', 'awayTeam'])->orderBy('game_date')->orderBy('id')->get()
            ->filter(fn (Game $game): bool => $this->quality->boxScoreIssues($game, $game->teamStats) === []);
    }

    /** @return list<int> */
    public function missingEpaGameIds(SupportCollection $games, bool $eligibleOnly = false): array
    {
        if ($games->isEmpty()) {
            return [];
        }
        $coverage = Play::query()->whereIn('game_id', $games->pluck('id'))
            ->whereNotNull('true_epa')->when($eligibleOnly, fn ($query) => $query->where('is_epa_eligible', true))
            ->select(['game_id', 'possession_team_id'])->distinct()->get()->groupBy('game_id');
        $missing = [];
        foreach ($games as $game) {
            $teams = $coverage->get($game->id, collect())->pluck('possession_team_id');
            if (! $teams->contains($game->home_team_id) || ! $teams->contains($game->away_team_id)
                || $teams->diff([$game->home_team_id, $game->away_team_id])->isNotEmpty()) {
                $missing[] = (int) $game->id;
            }
        }

        return $missing;
    }
}
