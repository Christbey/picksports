<?php

namespace App\Actions\ESPN;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_STAT_MODEL_CLASS = Model::class;

    public function execute(array $gameData, Model $game): int
    {
        if (! isset($gameData['boxscore']['teams'])) {
            return 0;
        }

        $teamStatModel = $this->teamStatModelClass();
        $teamStatModel::query()->where('game_id', $game->id)->delete();

        $synced = 0;
        $teamModel = $this->teamModelClass();

        foreach ($gameData['boxscore']['teams'] as $teamData) {
            $teamEspnId = $teamData['team']['id'] ?? null;
            if (! $teamEspnId) {
                continue;
            }

            $team = $teamModel::query()->where('espn_id', $teamEspnId)->first();

            if (! $team) {
                continue;
            }

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            $attributes = [
                'team_id' => $team->id,
                'game_id' => $game->id,
                ...$this->baseAttributes($stats),
                ...$this->sportSpecificAttributes($stats),
            ];

            $teamType = $this->resolveTeamType($team, $game, $teamData);
            if ($teamType !== null) {
                $attributes['team_type'] = $teamType;
            }

            $teamStatModel::create($attributes);

            $synced++;
        }

        return $synced;
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
