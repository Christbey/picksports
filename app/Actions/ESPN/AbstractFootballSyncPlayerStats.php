<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use App\Services\ESPN\FootballPlayerStatParser;
use DomainException;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballSyncPlayerStats
{
    use ValidatesBoxscoreReplacement;

    protected const EXTENDED_CATEGORIES = false;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const PLAYER_STAT_MODEL_CLASS = Model::class;

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
            try {
                return $this->replacePlayerStats($gameData, $game);
            } catch (DomainException) {
                return 0;
            }
        });
    }

    protected function replacePlayerStats(array $gameData, Model $game): int
    {
        $sections = $gameData['boxscore']['players'] ?? null;
        if (! is_array($sections)) {
            return 0;
        }
        $statModel = $this->playerStatModelClass();
        $playerModel = $this->playerModelClass();
        $teams = $this->replacementTeams($sections, $game, $this->teamModelClass(), $statModel);
        if ($teams === null) {
            return 0;
        }
        $players = $playerModel::query()->whereIn('espn_id', collect($sections)->flatMap(fn ($team) => collect($team['statistics'] ?? [])->flatMap(fn ($category) => collect($category['athletes'] ?? [])->pluck('athlete.id'))))->get()->keyBy('espn_id');
        $rows = [];
        $synced = 0;
        foreach ($sections as $section) {
            if (! is_array($section['statistics'] ?? null) || $section['statistics'] === []) {
                return 0;
            }
            $team = $teams->get($section['team']['id']);
            foreach ($section['statistics'] as $category) {
                if (! is_array($category['athletes'] ?? null)) {
                    return 0;
                }
                foreach ($category['athletes'] as $athlete) {
                    $player = $players->get(data_get($athlete, 'athlete.id'));
                    if (! $player || ! is_array($athlete['stats'] ?? null) || $athlete['stats'] === []) {
                        return 0;
                    }
                    $updates = app(FootballPlayerStatParser::class)->parse((string) ($category['name'] ?? ''), $category['labels'] ?? [], $athlete['stats'], static::EXTENDED_CATEGORIES);
                    if ($updates === []) {
                        continue;
                    }
                    if (isset($rows[$player->id]) && (int) $rows[$player->id]['team_id'] !== (int) $team->id) {
                        return 0;
                    }
                    $rows[$player->id] = $this->mergeObservedValues($rows[$player->id] ?? [], ['player_id' => $player->id, 'game_id' => $game->id, 'team_id' => $team->id, ...$updates]);
                    $synced++;
                }
            }
        }
        if ($rows === []) {
            return 0;
        }

        if (! $this->preservesObservedFields($statModel, $game, $rows, ['player_id'], (new $statModel)->getFillable())) {
            return 0;
        }
        $statModel::query()->where('game_id', $game->id)->delete();
        foreach ($rows as $row) {
            $statModel::query()->create($row);
        }

        return $synced;
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
