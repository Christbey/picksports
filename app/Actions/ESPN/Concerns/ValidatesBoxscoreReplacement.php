<?php

namespace App\Actions\ESPN\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait ValidatesBoxscoreReplacement
{
    /**
     * Resolve identities before replacing any rows. A partial team feed must not
     * remove the other team's already stored observations.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  class-string<Model>  $teamModel
     * @param  class-string<Model>  $statModel
     * @return Collection<int, Model>|null
     */
    protected function replacementTeams(array $sections, Model $game, string $teamModel, string $statModel): ?Collection
    {
        if ($sections === []) {
            return null;
        }
        $ids = collect($sections)->map(fn ($section) => data_get($section, 'team.id'));
        if ($ids->contains(fn ($id) => ! filled($id)) || $ids->unique()->count() !== $ids->count()) {
            return null;
        }
        $teams = $teamModel::query()->whereIn('espn_id', $ids)->get()->keyBy('espn_id');
        if ($teams->count() !== $ids->count()
            || $teams->contains(fn (Model $team) => ! in_array((int) $team->getKey(), [(int) $game->home_team_id, (int) $game->away_team_id], true))
            || $statModel::query()->where('game_id', $game->getKey())->whereNotIn('team_id', $teams->modelKeys())->exists()) {
            return null;
        }

        return $teams;
    }
}
