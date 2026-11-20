<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballSyncTeamStats
{
    use ValidatesBoxscoreReplacement;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_STAT_MODEL_CLASS = Model::class;

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

            return $this->replaceTeamStats($gameData, $game);
        });
    }

    protected function replaceTeamStats(array $gameData, Model $game): int
    {
        $sections = $gameData['boxscore']['teams'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return 0;
        }

        $teamStatModel = $this->teamStatModelClass();

        $synced = 0;
        $teamModel = $this->teamModelClass();
        $teams = $this->replacementTeams($sections, $game, $teamModel, $teamStatModel);
        if ($teams === null) {
            return 0;
        }
        $rows = [];

        foreach ($sections as $teamData) {
            $team = $teams->get($teamData['team']['id']);
            if (! is_array($teamData['statistics'] ?? null) || $teamData['statistics'] === []) {
                return 0;
            }

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            $attributes = [
                'team_id' => $team->id,
                'game_id' => $game->id,
                ...$this->baseAttributes($stats),
                ...$this->sportSpecificAttributes($stats),
            ];

            if (collect(array_diff_key($attributes, array_flip(['team_id', 'game_id'])))->every(fn ($value): bool => $value === null)) {
                return 0;
            }

            $teamType = $this->resolveTeamType($team, $game, $teamData);
            if ($teamType !== null) {
                $attributes['team_type'] = $teamType;
            }

            $rows[] = $attributes;

            $synced++;
        }

        $this->clearExisting($game);
        foreach ($rows as $attributes) {
            $this->storeStat($attributes);
        }

        return $synced;
    }

    protected function clearExisting(Model $game): void
    {
        $model = $this->teamStatModelClass();
        $model::where('game_id', $game->id)->delete();
    }

    protected function storeStat(array $attributes): void
    {
        $model = $this->teamStatModelClass();
        $model::create($attributes);
    }

    protected function baseAttributes(array $stats): array
    {
        return [
            'total_yards' => $stats['totalYards'] ?? null,
            'passing_yards' => $stats['passingYards'] ?? null,
            'rushing_yards' => $stats['rushingYards'] ?? null,
            'first_downs' => $stats['firstDowns'] ?? null,
            'third_down_conversions' => $stats['thirdDownConversions'] ?? null,
            'third_down_attempts' => $stats['thirdDownAttempts'] ?? null,
            'fourth_down_conversions' => $stats['fourthDownConversions'] ?? null,
            'fourth_down_attempts' => $stats['fourthDownAttempts'] ?? null,
            'penalties' => $stats['penalties'] ?? null,
            'penalty_yards' => $stats['penaltyYards'] ?? null,
        ];
    }

    protected function parseFraction(string $value, string $firstKey, ?string $secondKey, array &$parsed): void
    {
        $separator = str_contains($value, '/') ? '/' : '-';
        $parts = explode($separator, $value);

        if (count($parts) === 2) {
            $parsed[$firstKey] = (int) $parts[0];
            if ($secondKey) {
                $parsed[$secondKey] = (int) $parts[1];
            }
        }
    }

    protected function resolveTeamType(Model $team, Model $game, array $teamData): ?string
    {
        return null;
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
    protected function teamStatModelClass(): string
    {
        if (static::TEAM_STAT_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_STAT_MODEL_CLASS must be defined.');
        }

        return static::TEAM_STAT_MODEL_CLASS;
    }

    abstract protected function parseTeamStats(array $statistics): array;

    abstract protected function sportSpecificAttributes(array $stats): array;
}
