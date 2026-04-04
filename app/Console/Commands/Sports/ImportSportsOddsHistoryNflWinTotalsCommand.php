<?php

namespace App\Console\Commands\Sports;

use App\Models\NFL\Team;
use App\Services\Sports\SportsOddsHistoryNflWinTotalsImportService;
use Illuminate\Console\Command;

class ImportSportsOddsHistoryNflWinTotalsCommand extends Command
{
    protected $signature = 'sports:import-soh-nfl-team-win-totals
        {--season=* : Limit import to one or more seasons}
        {--team=* : Limit import to one or more NFL team abbreviations}';

    protected $description = 'Import historical NFL team win totals from Sports Odds History';

    public function handle(SportsOddsHistoryNflWinTotalsImportService $importService): int
    {
        $teams = $this->resolvedTeams();
        if ($teams->isEmpty()) {
            $this->error('No NFL teams matched the supplied filters.');

            return self::FAILURE;
        }

        $seasons = $this->resolvedSeasons();

        $this->info('Importing Sports Odds History NFL win totals for '.$teams->count().' teams...');

        $count = $importService->import($teams, $seasons);

        $this->info("Stored/updated {$count} win total snapshot rows.");

        return self::SUCCESS;
    }

    protected function resolvedTeams()
    {
        $abbreviations = $this->option('team');
        if (! is_array($abbreviations) || $abbreviations === []) {
            return Team::query()->orderBy('abbreviation')->get();
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($team) => strtoupper(trim((string) $team)),
            $abbreviations
        ))));

        return Team::query()
            ->whereIn('abbreviation', $normalized)
            ->orderBy('abbreviation')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    protected function resolvedSeasons(): array
    {
        $seasons = $this->option('season');
        if (! is_array($seasons) || $seasons === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($season) => (int) $season,
            array_filter($seasons, static fn ($season) => trim((string) $season) !== '')
        )));
    }
}
