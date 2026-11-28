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

    /** Reject replacement that would discard previously observed core facts. */
    protected function preservesObservedFields(string $statModel, Model $game, array $rows, array $keys, array $core = []): bool
    {
        $key = static fn (array $row): string => implode('|', array_map(fn ($field) => (string) ($row[$field] ?? ''), $keys));
        $incoming = collect($rows)->keyBy($key);
        foreach ($statModel::query()->where('game_id', $game->getKey())->lockForUpdate()->get() as $old) {
            $row = $incoming->get($key($old->getAttributes()));
            $fields = $core !== [] ? $core : ($row !== null ? array_keys($row) : $old->getFillable());
            $fields = array_diff($fields, ['id', 'game_id', 'player_id', 'team_id', 'stat_type', 'team_type', 'created_at', 'updated_at']);
            foreach ($fields as $field) {
                if ($old->getAttribute($field) !== null && ($row === null || ! array_key_exists($field, $row) || $row[$field] === null)) {
                    return false;
                }
            }
            if ($row !== null && isset($row['team_id']) && (int) $old->team_id !== (int) $row['team_id']) {
                return false;
            }
        }

        return true;
    }

    protected function mergeObservedValues(array $prior, array $incoming): array
    {
        foreach ($incoming as $field => $value) {
            if ($value === null) {
                if (! array_key_exists($field, $prior)) {
                    $prior[$field] = null;
                }

                continue;
            }
            if (array_key_exists($field, $prior) && $prior[$field] !== null && $prior[$field] !== $value) {
                throw new \DomainException('Conflicting source observations.');
            }
            $prior[$field] = $value;
        }

        return $prior;
    }
}
