<?php

namespace App\Console\Commands\MLB;

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Services\MLB\MlbLineScoreBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class BackfillPeriodHistoryCommand extends Command
{
    protected $signature = 'mlb:backfill-period-history
        {--from-season=2021 : First MLB season}
        {--to-season=2025 : Last MLB season}
        {--sleep-ms=75 : Delay between scoreboard dates}
        {--skip-discovery : Repair existing games without discovering missing dates}';

    protected $description = 'Discover MLB historical games and backfill F3/F5 inning line scores';

    public function handle(
        SyncGamesFromScoreboard $sync,
        MlbLineScoreBackfillService $lineScores,
    ): int {
        $from = (int) $this->option('from-season');
        $to = (int) $this->option('to-season');
        if ($from < 1900 || $to < $from) {
            $this->error('The requested season range is invalid.');

            return self::FAILURE;
        }

        $dates = 0;
        $synchronized = 0;
        $failures = [];
        if (! $this->option('skip-discovery')) {
            for ($season = $from; $season <= $to; $season++) {
                $date = Carbon::create($season, 3, 1)->startOfDay();
                $end = Carbon::create($season, 10, 31)->startOfDay();
                while ($date->lte($end)) {
                    try {
                        $synchronized += $sync->execute($date->format('Ymd'));
                    } catch (Throwable $exception) {
                        $failures[] = [
                            'date' => $date->toDateString(),
                            'error' => $exception->getMessage(),
                        ];
                    }
                    $dates++;
                    if ((int) $this->option('sleep-ms') > 0) {
                        usleep((int) $this->option('sleep-ms') * 1000);
                    }
                    $date->addDay();
                }
            }
        }

        $repair = [
            'games' => 0,
            'dates' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'failed_dates' => 0,
            'event_fallbacks' => 0,
        ];
        for ($season = $from; $season <= $to; $season++) {
            $seasonRepair = $lineScores->backfill(
                season: $season,
                fromDate: "{$season}-03-01",
                toDate: "{$season}-10-31",
                sleepMilliseconds: max(0, (int) $this->option('sleep-ms')),
            );
            foreach ($repair as $key => $value) {
                $repair[$key] += $seasonRepair[$key];
            }
        }

        $this->line(json_encode([
            'from_season' => $from,
            'to_season' => $to,
            'scoreboard_dates' => $dates,
            'games_synchronized' => $synchronized,
            'scoreboard_failures' => $failures,
            'line_score_repair' => $repair,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $failures === [] && $repair['failed_dates'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
