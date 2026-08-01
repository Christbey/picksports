<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Support\CFB\CfbWeek;
use Illuminate\Console\Command;

class NormalizeRegularSeasonWeeksCommand extends Command
{
    protected $signature = 'cfb:normalize-regular-season-weeks
        {--season= : Season to normalize; defaults to cfb.season.default}
        {--dry-run : Show changes without updating rows}';

    protected $description = 'Normalize stored CFB regular-season week numbers from game dates, including Week 0';

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: config('cfb.season.default', date('Y')));
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $samples = [];

        Game::query()
            ->where('season', $season)
            ->whereIn('season_type', ['regular', '2', (string) config('cfb.season.types.regular', 2)])
            ->whereNotNull('game_date')
            ->orderBy('id')
            ->chunkById(500, function ($games) use ($season, $dryRun, &$changed, &$samples): void {
                foreach ($games as $game) {
                    /** @var Game $game */
                    $date = $game->game_date?->toDateString();

                    if (! $date) {
                        continue;
                    }

                    $expectedWeek = CfbWeek::productWeekForDate($season, $date);
                    $currentWeek = (int) $game->week;

                    if ($currentWeek === $expectedWeek) {
                        continue;
                    }

                    $changed++;
                    if (count($samples) < 10) {
                        $samples[] = [
                            (string) $game->id,
                            $date,
                            (string) $currentWeek,
                            (string) $expectedWeek,
                            (string) ($game->short_name ?? $game->name ?? ''),
                        ];
                    }

                    if (! $dryRun) {
                        $game->forceFill(['week' => $expectedWeek])->save();
                    }
                }
            });

        if ($samples !== []) {
            $this->table(['Game ID', 'Date', 'Old Week', 'New Week', 'Matchup'], $samples);
        }

        $this->info(sprintf(
            '%s %d CFB regular-season game week value%s for season %d.',
            $dryRun ? 'Would normalize' : 'Normalized',
            $changed,
            $changed === 1 ? '' : 's',
            $season
        ));

        return self::SUCCESS;
    }
}
