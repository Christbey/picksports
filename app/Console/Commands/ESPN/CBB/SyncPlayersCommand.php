<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncPlayers;
use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\CBB\FetchPlayers;
use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use App\Support\BasketballSeason;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-players';

    protected const SPORT_CODE = 'CBB';

    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;

    protected function buildSignature(): string
    {
        return parent::buildSignature().' {--season=} {--sync : Run inline} {--limit=} {--offset=0} {--include-prior : Seed a missing prior-season roster} {--division-i : Limit bulk sync to explicitly classified Division I teams}';
    }

    public function handle(): int
    {
        $season = $this->option('season') ?: BasketballSeason::forDate();
        if (! ctype_digit((string) $season) || (int) $season < 2000 || (int) $season > 2100) {
            $this->error('A valid ending-year season is required.');

            return self::INVALID;
        }
        $season = (int) $season;
        if (($this->option('limit') !== null && (! ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1)) || ! ctype_digit((string) $this->option('offset'))) {
            $this->error('Limit must be positive and offset must be nonnegative.');

            return self::INVALID;
        }

        $query = Team::query()->where('espn_id', '>', 0)->whereNotIn('abbreviation', ['TBD', 'TBD2', 'WFF', 'FF'])
            ->when($this->argument('teamEspnId'), fn (Builder $q, string $id) => $q->where('espn_id', $id))
            ->when(! $this->argument('teamEspnId'), fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereHas('homeGames', fn (Builder $g) => $g->where('season', $season))->orWhereHas('awayGames', fn (Builder $g) => $g->where('season', $season))))
            ->when($this->option('division-i'), fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereIn('division', ['NCAA Division I', 'Division I'])->orWhere('conference', 'NCAA Division I')))
            ->orderBy('id');
        if ((int) $this->option('offset') > 0) {
            $query->skip((int) $this->option('offset'))->take((int) ($this->option('limit') ?: 100000));
        }
        if ($this->option('limit')) {
            $query->take(max(1, (int) $this->option('limit')));
        }
        $teams = $query->get();
        $success = $missing = $failed = 0;
        foreach ($teams as $team) {
            if (! $this->option('sync')) {
                FetchPlayers::dispatch((string) $team->espn_id, $season, (bool) $this->option('include-prior'));

                continue;
            }
            try {
                $count = app(SyncPlayers::class)->execute((string) $team->espn_id, $season);
                if ($count > 0 && $this->option('include-prior') && ! RosterSnapshot::knownAt($team->id, $season - 1, now())) {
                    app(SyncPlayers::class)->execute((string) $team->espn_id, $season - 1);
                }
                $count > 0 ? $success++ : $missing++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->warn('Roster rejected for team '.$team->id.': '.$exception->getMessage());
            }
        }
        $this->info($this->option('sync') ? "CBB season {$season} rosters: {$success} synced, {$missing} unavailable, {$failed} failed." : 'CBB season '.$season.' roster jobs dispatched: '.$teams->count());

        return $failed > 0 || $teams->isEmpty() || ($this->option('sync') && $success === 0) ? self::FAILURE : self::SUCCESS;
    }
}
