<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use App\Support\ESPN\BasketballStatValue;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractBasketballSyncPlayerStats
{
    use ValidatesBoxscoreReplacement;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const PLAYER_STAT_MODEL_CLASS = Model::class;

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

            return $this->replacePlayerStats($gameData, $game);
        });
    }

    protected function replacePlayerStats(array $gameData, Model $game): int
    {
        $sections = $gameData['boxscore']['players'] ?? null;
        if (! is_array($sections)) {
            return 0;
        }
        $playerStatModel = $this->playerStatModelClass();
        $playerModel = $this->playerModelClass();
        $teams = $this->replacementTeams($sections, $game, $this->teamModelClass(), $playerStatModel);
        if ($teams === null) {
            return 0;
        }
        $athletes = [];
        foreach ($sections as $section) {
            $category = data_get($section, 'statistics.0');
            $labels = is_array($category) ? ($category['labels'] ?? null) : null;
            $rows = is_array($category) ? ($category['athletes'] ?? null) : null;
            if (! is_array($rows) || $rows === []) {
                return 0;
            }
            foreach ($rows as $athleteData) {
                if ($this->shouldSkipAthlete($athleteData)) {
                    continue;
                }
                if (! filled(data_get($athleteData, 'athlete.id')) || ! is_array($athleteData['stats'] ?? null) || $athleteData['stats'] === []) {
                    return 0;
                }
                $parsed = $this->parseLabeledStats($labels, $athleteData['stats']);
                if ($parsed === null) {
                    return 0;
                }
                $athletes[] = [$athleteData, $teams->get($section['team']['id']), $parsed];
            }
        }
        if ($athletes === []) {
            return 0;
        }
        $players = $playerModel::query()->whereIn('espn_id', collect($athletes)->map(fn ($row) => $row[0]['athlete']['id']))->get()->keyBy('espn_id');

        $rows = [];
        foreach ($athletes as [$athleteData, $team, $parsed]) {
            $playerEspnId = (string) $athleteData['athlete']['id'];
            $player = $this->resolvePlayer($playerModel, $playerEspnId, $athleteData, $team, $players->get($playerEspnId));
            $players->put($playerEspnId, $player);
            $rows[$player->id] = [
                'player_id' => $player->id,
                'game_id' => $game->id,
                'team_id' => $team->id,
                ...$parsed,
            ];
        }
        $playerStatModel::query()->where('game_id', $game->id)->delete();
        foreach ($rows as $row) {
            $playerStatModel::query()->create($row);
        }

        return count($athletes);
    }

    /**
     * @param  class-string<Model>  $playerModel
     */
    protected function resolvePlayer(string $playerModel, string $playerEspnId, array $athleteData, Model $team, ?Model $existing = null): Model
    {
        $athlete = $athleteData['athlete'] ?? [];
        $attributes = [
            'team_id' => $team->getKey(),
            'first_name' => $athlete['firstName'] ?? null,
            'last_name' => $athlete['lastName'] ?? null,
            'full_name' => $athlete['displayName'] ?? $athlete['fullName'] ?? $athlete['shortName'] ?? null,
            'jersey_number' => $athlete['jersey'] ?? null,
            'position' => $athlete['position']['abbreviation'] ?? $athlete['position']['name'] ?? null,
            'headshot_url' => $athlete['headshot']['href'] ?? null,
        ];

        $fillable = $playerModel::query()->getModel()->getFillable();
        $attributes = array_filter(
            $attributes,
            fn ($value, string $key): bool => $value !== null && in_array($key, $fillable, true),
            ARRAY_FILTER_USE_BOTH
        );

        $player = $existing ?? new $playerModel(['espn_id' => $playerEspnId]);
        $player->fill($attributes)->save();

        return $player;
    }

    protected function shouldSkipAthlete(array $athleteData): bool
    {
        // Only explicit provider DNP is absence of participation; an empty
        // unmarked row is incomplete evidence and invalidates replacement.
        return ($athleteData['didNotPlay'] ?? false) === true;
    }

    /** Required labels are explicit; never infer a provider layout from positions. */
    private function parseLabeledStats(mixed $labels, array $stats): ?array
    {
        if (! is_array($labels) || ! array_is_list($labels) || ! array_is_list($stats)
            || count($labels) !== count($stats)) {
            return null;
        }
        $values = [];
        foreach ($labels as $index => $label) {
            if (! is_string($label) || trim($label) === '') {
                return null;
            }
            $label = strtoupper(trim($label));
            if (array_key_exists($label, $values)) {
                return null;
            }
            $values[$label] = $stats[$index];
        }
        $required = ['MIN', 'PTS', 'FG', '3PT', 'FT', 'REB', 'AST', 'TO', 'STL', 'BLK', 'OREB', 'DREB', 'PF'];
        if (array_diff($required, array_keys($values)) !== []) {
            return null;
        }
        $minutes = $values['MIN'];
        if ((! is_string($minutes) && ! is_int($minutes))
            || strlen((string) $minutes) > 10 || preg_match('/\A[0-9]+(?::[0-5][0-9])?\z/', trim((string) $minutes)) !== 1) {
            return null;
        }
        $parsed = ['minutes_played' => trim((string) $minutes)];
        foreach (['PTS' => 'points', 'REB' => 'rebounds_total', 'AST' => 'assists', 'TO' => 'turnovers',
            'STL' => 'steals', 'BLK' => 'blocks', 'OREB' => 'rebounds_offensive', 'DREB' => 'rebounds_defensive', 'PF' => 'fouls'] as $label => $field) {
            $value = BasketballStatValue::integer($values[$label]);
            if ($value === null) {
                return null;
            }
            $parsed[$field] = $value;
        }
        foreach (['FG' => 'field_goals', '3PT' => 'three_point', 'FT' => 'free_throws'] as $label => $field) {
            $pair = BasketballStatValue::madeAttempt($values[$label]);
            if ($pair === null) {
                return null;
            }
            [$parsed[$field.'_made'], $parsed[$field.'_attempted']] = $pair;
        }

        return $parsed;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        if (static::PLAYER_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerStatModelClass(): string
    {
        if (static::PLAYER_STAT_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_STAT_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_STAT_MODEL_CLASS;
    }
}
