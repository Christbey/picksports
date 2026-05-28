<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use Illuminate\Database\Eloquent\Collection;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-game-details';

    protected const SPORT_CODE = 'CBB';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function pendingGamesDescriptor(): string
    {
        return 'CBB games missing player stats, team stats, or final play data';
    }

    protected function pendingGames(): Collection
    {
        return Game::query()
            ->whereNotNull('espn_event_id')
            ->when($this->lookbackDays() !== null, fn ($query) => $query->whereDate('game_date', '>=', now()->copy()->subDays($this->lookbackDays())->toDateString()))
            ->where(function ($query) {
                if ($this->option('refresh-existing')) {
                    $query->where('status', 'STATUS_FINAL');

                    return;
                }

                $query->whereDoesntHave('playerStats')
                    ->orWhereDoesntHave('teamStats')
                    ->orWhere(fn ($scoreQuery) => $this->applyMissingFinalScoreFilter($scoreQuery))
                    ->orWhere(fn ($playQuery) => $this->applyMissingFinalPlaysFilter($playQuery))
                    ->orWhere(fn ($playQuery) => $this->applyIncompleteFinalPlayScoreFilter($playQuery));
            })
            ->orderBy('game_date', $this->option('latest') ? 'desc' : 'asc')
            ->get();
    }

    private function applyMissingFinalScoreFilter($query): void
    {
        $query
            ->where('status', 'STATUS_FINAL')
            ->where(function ($scoreQuery) {
                $scoreQuery
                    ->whereNull('home_score')
                    ->orWhereNull('away_score');
            });
    }

    private function applyMissingFinalPlaysFilter($query): void
    {
        $query
            ->where('status', 'STATUS_FINAL')
            ->whereDoesntHave('plays');
    }

    private function applyIncompleteFinalPlayScoreFilter($query): void
    {
        $query
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereExists(function ($subQuery) {
                $subQuery
                    ->selectRaw('1')
                    ->from('cbb_plays as latest_cbb_play')
                    ->whereColumn('latest_cbb_play.game_id', 'cbb_games.id')
                    ->whereRaw('latest_cbb_play.sequence_number = (select max(sequence_number) from cbb_plays where cbb_plays.game_id = cbb_games.id)')
                    ->where(function ($scoreQuery) {
                        $scoreQuery
                            ->whereColumn('latest_cbb_play.home_score', '!=', 'cbb_games.home_score')
                            ->orWhereColumn('latest_cbb_play.away_score', '!=', 'cbb_games.away_score');
                    });
            });
    }
}
