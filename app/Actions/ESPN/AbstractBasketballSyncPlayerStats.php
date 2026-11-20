<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractBasketballSyncPlayerStats
{
    use ValidatesBoxscoreReplacement;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const PLAYER_STAT_MODEL_CLASS = Model::class;

    protected const SKIP_DNP_OR_EMPTY_STATS = false;

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

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
            $rows = data_get($section, 'statistics.0.athletes');
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
                $athletes[] = [$athleteData, $teams->get($section['team']['id'])];
            }
        }
        if ($athletes === []) {
            return 0;
        }
        $players = $playerModel::query()->whereIn('espn_id', collect($athletes)->map(fn ($row) => $row[0]['athlete']['id']))->get()->keyBy('espn_id');

        $rows = [];
        foreach ($athletes as [$athleteData, $team]) {
            $playerEspnId = (string) $athleteData['athlete']['id'];
            $player = $this->resolvePlayer($playerModel, $playerEspnId, $athleteData, $team, $players->get($playerEspnId));
            $players->put($playerEspnId, $player);
            $stats = $athleteData['stats'] ?? [];

            [$fgMade, $fgAttempted] = $this->parseMadeAttempt($stats[2] ?? null);
            [$threeMade, $threeAttempted] = $this->parseMadeAttempt($stats[3] ?? null);
            [$ftMade, $ftAttempted] = $this->parseMadeAttempt($stats[4] ?? null);

            $rows[$player->id] = [
                'player_id' => $player->id,
                'game_id' => $game->id,
                'team_id' => $team->id,
                'minutes_played' => $stats[0] ?? null,
                'points' => isset($stats[1]) ? (int) $stats[1] : 0,
                'field_goals_made' => $fgMade,
                'field_goals_attempted' => $fgAttempted,
                'three_point_made' => $threeMade,
                'three_point_attempted' => $threeAttempted,
                'free_throws_made' => $ftMade,
                'free_throws_attempted' => $ftAttempted,
                'rebounds_total' => isset($stats[5]) ? (int) $stats[5] : 0,
                'assists' => isset($stats[6]) ? (int) $stats[6] : 0,
                'turnovers' => isset($stats[7]) ? (int) $stats[7] : 0,
                'steals' => isset($stats[8]) ? (int) $stats[8] : 0,
                'blocks' => isset($stats[9]) ? (int) $stats[9] : 0,
                'rebounds_offensive' => isset($stats[10]) ? (int) $stats[10] : 0,
                'rebounds_defensive' => isset($stats[11]) ? (int) $stats[11] : 0,
                'fouls' => isset($stats[12]) ? (int) $stats[12] : 0,
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
        if (static::SKIP_DNP_OR_EMPTY_STATS) {
            return ($athleteData['didNotPlay'] ?? false) || empty($athleteData['stats']);
        }

        return false;
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function parseMadeAttempt(?string $value): array
    {
        if (! $value) {
            return [0, 0];
        }

        $parts = explode('-', $value);

        return [
            isset($parts[0]) ? (int) $parts[0] : 0,
            isset($parts[1]) ? (int) $parts[1] : 0,
        ];
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
