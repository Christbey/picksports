<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbGameDataValidator;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-game-details';

    protected const SPORT_CODE = 'CFB';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function whereMissingDetails($query, string $gameModel): void
    {
        parent::whereMissingDetails($query, $gameModel);
        foreach (['boxscore', 'plays'] as $component) {
            $query->orWhereNotExists(function ($q) use ($component) {
                $q->selectRaw('1')->from('cfb_game_data_checks')
                    ->whereColumn('cfb_game_data_checks.game_id', 'cfb_games.id')
                    ->where('component', $component)->where('validator_version', CfbGameDataValidator::VERSION)
                    ->whereNotNull('accepted_at');
            });
        }
        // Providers issue final corrections even when all rows already exist.
        $query->orWhere(fn ($q) => $q->where('status', 'STATUS_FINAL')->whereDate('game_date', '>=', now()->subDay()->toDateString()));
    }
}
