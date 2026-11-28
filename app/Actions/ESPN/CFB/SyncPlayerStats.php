<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncPlayerStats;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbPlayerIdentityResolver;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\ESPN\FootballPlayerStatParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    public function execute(array $gameData, Model $game, bool $recordSource = true): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game, $recordSource): int {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $game->load(['homeTeam', 'awayTeam']);
            $validation = app(CfbGameDataValidator::class)->boxscore($gameData, $game);
            if ($recordSource) {
                app(CfbSourceRevision::class)->record($game, 'boxscore', $gameData, $validation);
            }
            if ($validation['state'] !== 'complete') {
                return 0;
            }
            $athleteIds = collect($gameData['boxscore']['players'])->flatMap(fn ($team) => collect($team['statistics'])->flatMap(fn ($category) => collect($category['athletes'] ?? [])->pluck('athlete.id')))->filter();
            $players = Player::whereIn('espn_id', $athleteIds)->get()->keyBy('espn_id');
            $existing = PlayerStat::where('game_id', $game->id)->get()->keyBy('player_id');
            $rows = [];
            $staged = [];
            $teams = collect([$game->homeTeam, $game->awayTeam])->keyBy('espn_id');
            foreach ($gameData['boxscore']['players'] as $teamData) {
                $team = $teams->get($teamData['team']['id']);
                foreach ($teamData['statistics'] as $category) {
                    foreach ($category['athletes'] ?? [] as $athlete) {
                        if ((int) data_get($athlete, 'athlete.id') <= 0) {
                            continue;
                        }
                        $espnId = (string) data_get($athlete, 'athlete.id');
                        $player = $players->get($espnId);
                        $updates = app(FootballPlayerStatParser::class)->parse((string) ($category['name'] ?? ''), $category['labels'] ?? [], $athlete['stats'] ?? []);
                        if ($updates === []) {
                            continue;
                        }
                        if (isset($staged[$espnId]) && (int) $staged[$espnId]['team_id'] !== (int) $team->id) {
                            return 0;
                        }
                        $staged[$espnId] = $this->mergeObservedValues($staged[$espnId] ?? [], ['team_id' => $team->id, ...$updates]);
                        if ($player !== null) {
                            $rows[$player->id] = $staged[$espnId];
                        }
                    }
                }
            }
            if (! $this->preservesObservedFields(PlayerStat::class, $game, collect($rows)->map(fn ($row, $id) => ['player_id' => $id, ...$row])->all(), ['player_id'], (new PlayerStat)->getFillable())) {
                return 0;
            }
            if ($staged === []) {
                return 0;
            }
            app(CfbPlayerIdentityResolver::class)->resolve($gameData, $game);
            $players = Player::whereIn('espn_id', array_keys($staged))->get()->keyBy('espn_id');
            $rows = [];
            foreach ($staged as $espnId => $values) {
                $player = $players->get($espnId) ?? throw new ModelNotFoundException('Resolved player missing.');
                $rows[$player->id] = $values;
            }
            $fields = array_diff((new PlayerStat)->getFillable(), ['player_id', 'game_id', 'team_id']);
            foreach ($rows as $id => $values) {
                $stat = $existing->get($id) ?? new PlayerStat(['player_id' => $id, 'game_id' => $game->id]);
                $stat->fill([...array_fill_keys($fields, null), ...$values])->save();
            }
            PlayerStat::where('game_id', $game->id)->whereNotIn('player_id', array_keys($rows))->delete();

            return count($rows);
        });
    }
}
