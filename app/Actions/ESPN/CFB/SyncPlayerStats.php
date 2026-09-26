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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SyncPlayerStats extends AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    public function execute(array $gameData, Model $game): int
    {
        $validation = app(CfbGameDataValidator::class)->boxscore($gameData, $game);
        $check = app(CfbSourceRevision::class)->record($game, 'boxscore', $gameData, $validation);
        if ($validation['state'] !== 'complete') {
            return 0;
        }

        return DB::transaction(function () use ($gameData, $game) {
            Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            app(CfbPlayerIdentityResolver::class)->resolve($gameData, $game);

            $rows = [];
            $teams = collect([$game->homeTeam, $game->awayTeam])->keyBy('espn_id');
            foreach ($gameData['boxscore']['players'] as $teamData) {
                $team = $teams->get($teamData['team']['id']);
                foreach ($teamData['statistics'] as $category) {
                    foreach ($category['athletes'] ?? [] as $athlete) {
                        if ((int) data_get($athlete, 'athlete.id') <= 0) {
                            continue;
                        }
                        $player = Player::where('espn_id', data_get($athlete, 'athlete.id'))->firstOrFail();
                        $mapped = $this->mapLabeledStats($category['labels'] ?? [], $athlete['stats'] ?? []);
                        $updates = $this->parseCategoryUpdates(strtolower($category['name'] ?? ''), $mapped);
                        $rows[$player->id] = [...($rows[$player->id] ?? []), 'team_id' => $team->id, ...$updates];
                    }
                }
            }
            $fields = array_diff((new PlayerStat)->getFillable(), ['player_id', 'game_id', 'team_id']);
            foreach ($rows as $id => $values) {
                PlayerStat::updateOrCreate(['player_id' => $id, 'game_id' => $game->id], [...array_fill_keys($fields, null), ...$values]);
            }
            PlayerStat::where('game_id', $game->id)->whereNotIn('player_id', array_keys($rows))->delete();

            return count($rows);
        });
    }

    protected function passingCompletionsField(): string
    {
        return 'passing_completions';
    }

    protected function passingAttemptsField(): string
    {
        return 'passing_attempts';
    }

    protected function interceptionsField(): string
    {
        return 'interceptions_thrown';
    }

    protected function rushingAttemptsField(): string
    {
        return 'rushing_attempts';
    }

    protected function receivingTargetsField(): string
    {
        return 'receiving_targets';
    }
}
