<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncCurrentWeekNumberCommand;
use App\Jobs\ESPN\CFB\FetchGames;
use App\Jobs\ESPN\CFB\FetchTeams;
use App\Models\CFB\Game;
use App\Support\CFB\CfbWeek;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncCurrentWeekCommand extends AbstractSyncCurrentWeekNumberCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-current';

    protected const SPORT_CODE = 'CFB';

    protected const SEASON_START_MONTH = 8;

    protected const SEASON_START_DAY = 29;

    protected const MAX_REGULAR_SEASON_WEEKS = 15;

    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;

    protected const WEEK_GAMES_SYNC_JOB_CLASS = FetchGames::class;

    public function handle(): int
    {
        $sport = $this->sportCode();
        $season = $this->resolveSeason();

        $this->info("Syncing {$sport} teams...");
        $this->dispatchTeamsSync();

        $windows = $this->resolveCurrentWindows($season);

        foreach ($windows as $window) {
            $seasonType = (int) $window['season_type'];
            $week = (int) $window['week'];
            $label = $seasonType === (int) config('cfb.season.types.postseason', 3)
                ? 'Postseason'
                : 'Regular Season';

            $this->info("Syncing {$sport} games for {$season} {$label} Week {$week}...");
            $this->dispatchSeasonTypeWeekGamesSync($season, $seasonType, $week);
        }

        $this->info('Sync jobs dispatched successfully.');

        return Command::SUCCESS;
    }

    protected function resolveSeason(): int
    {
        $currentYear = (int) now()->year;

        return now()->month <= 2 ? $currentYear - 1 : $currentYear;
    }

    /**
     * @return Collection<int, array{season_type:int,week:int}>
     */
    protected function resolveCurrentWindows(int $season): Collection
    {
        $daysBefore = (int) config('cfb.season.current_week_days_before', 3);
        $daysAfter = (int) config('cfb.season.current_week_days_after', 3);
        $start = Carbon::today()->subDays($daysBefore)->toDateString();
        $end = Carbon::today()->addDays($daysAfter)->toDateString();

        $windows = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', [
                (int) config('cfb.season.types.regular', 2),
                (int) config('cfb.season.types.postseason', 3),
            ])
            ->whereDate('game_date', '>=', $start)
            ->whereDate('game_date', '<=', $end)
            ->select('season_type', 'week')
            ->distinct()
            ->orderBy('season_type')
            ->orderBy('week')
            ->get()
            ->map(fn (Game $game): array => [
                'season_type' => (int) $game->season_type,
                'week' => (int) $game->week,
            ]);

        if ($windows->isNotEmpty()) {
            return $windows->values();
        }

        return collect([$this->fallbackWindow($season)]);
    }

    /**
     * @return array{season_type:int,week:int}
     */
    protected function fallbackWindow(int $season): array
    {
        $now = now();
        $regularSeasonWeek = $this->getCurrentWeekForSeason($season);
        $postseasonStart = CfbWeek::weekOneStartDate($season)->addWeeks($this->maxRegularSeasonWeeks());

        if ($regularSeasonWeek < $this->maxRegularSeasonWeeks() || $now->lessThan($postseasonStart)) {
            return [
                'season_type' => $this->regularSeasonType(),
                'week' => $regularSeasonWeek,
            ];
        }

        $postseasonWeek = min(
            max(1, $now->diffInWeeks($postseasonStart) + 1),
            (int) config('cfb.season.weeks.postseason', 4)
        );

        return [
            'season_type' => (int) config('cfb.season.types.postseason', 3),
            'week' => $postseasonWeek,
        ];
    }

    protected function getCurrentWeekForSeason(int $season): int
    {
        $now = now();
        if ($now->lessThan(CfbWeek::weekOneStartDate($season))) {
            return 0;
        }

        return CfbWeek::productWeekForDate($season, $now);
    }

    protected function dispatchSeasonTypeWeekGamesSync(int $season, int $seasonType, int $week): void
    {
        $job = $this->weekGamesSyncJobClass();
        $job::dispatch($season, $seasonType, $week);
    }
}
