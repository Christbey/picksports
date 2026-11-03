<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use App\Services\CBB\CbbDataQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-game-details';

    protected const SPORT_CODE = 'CBB';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;

    protected function continueAfterDetailFailure(): bool
    {
        return true;
    }

    protected function pendingGamesDescriptor(): string
    {
        return 'CBB games missing player stats, team stats, or final play data';
    }

    protected function pendingGames(): Collection
    {
        return app(CbbDataQuality::class)->realGames(Game::query())
            ->when(! $this->option('refresh-existing'), fn (Builder $query) => $query->where(fn (Builder $due) => $due->whereNull('details_attempted_at')->orWhere('details_attempted_at', '<=', now()->subMinutes(30))))
            ->whereIn('status', ['STATUS_FINAL', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_DELAYED'])
            ->when($this->lookbackDays() !== null, fn ($query) => $query->whereDate('game_date', '>=', now()->copy()->subDays($this->lookbackDays())->toDateString()))
            ->when($this->daysForward() !== null, fn ($query) => $query->whereDate('game_date', '<=', now()->copy()->addDays($this->daysForward())->toDateString()))
            ->where(function ($query) {
                if ($this->option('refresh-existing')) {
                    $query->where('status', 'STATUS_FINAL');

                    return;
                }

                $query->whereDoesntHave('playerStats')
                    ->orWhere(fn (Builder $stats) => $stats->has('teamStats', '<', 2))
                    ->orWhere(fn (Builder $stats) => $stats->where('status', 'STATUS_FINAL')->whereNotIn('id', app(CbbDataQuality::class)->reconciledGames(Game::query())->select('cbb_games.id')))
                    ->orWhere(fn ($scoreQuery) => $this->applyMissingFinalScoreFilter($scoreQuery))
                    ->orWhere(fn ($playQuery) => $this->applyMissingFinalPlaysFilter($playQuery))
                    ->orWhere(fn ($playQuery) => $this->applyIncompleteFinalPlayScoreFilter($playQuery));
            })
            ->orderByRaw("case when status = 'STATUS_FINAL' then 0 else 1 end")
            ->orderByRaw('case when details_attempted_at is null then 0 else 1 end')
            ->orderBy('details_attempted_at')
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
