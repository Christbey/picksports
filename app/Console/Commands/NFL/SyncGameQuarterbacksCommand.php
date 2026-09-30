<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflGameQuarterbackIdentitySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncGameQuarterbacksCommand extends Command
{
    protected $signature = 'nfl:sync-game-quarterbacks {--season= : Required season} {--file= : Local nflverse schedules CSV; otherwise fetch the public source} {--apply : Fill missing final-game QB identities; default is read-only}';

    protected $description = 'Audit or fill missing final-game starting quarterbacks from verified nflverse schedule identities';

    public function handle(NflGameQuarterbackIdentitySync $sync): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 1999 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid --season.');

            return self::FAILURE;
        }
        try {
            $csv = $this->option('file')
                ? file_get_contents((string) $this->option('file'))
                : Http::timeout(30)->retry(2, 500)->get(NflGameQuarterbackIdentitySync::SOURCE_URL)->throw()->body();
            if (! is_string($csv) || trim($csv) === '') {
                throw new \RuntimeException('Empty nflverse schedule source.');
            }
            $report = $sync->sync($csv, $season, (bool) $this->option('apply'));
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['unresolved_games'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
