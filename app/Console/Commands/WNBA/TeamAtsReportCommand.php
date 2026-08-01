<?php

namespace App\Console\Commands\WNBA;

use App\Services\WNBA\WnbaPredictionSignalService;
use Illuminate\Console\Command;

class TeamAtsReportCommand extends Command
{
    protected $signature = 'wnba:team-ats-report
        {--season= : Filter by season}
        {--json : Output the report as JSON}';

    protected $description = 'Report WNBA team ATS records from final scores and stored spread lines';

    public function handle(WnbaPredictionSignalService $signals): int
    {
        $season = (int) ($this->option('season') ?: config('wnba.season.default', date('Y')));
        $teams = $signals->teamAtsReport($season);
        $missingSpreadLines = $signals->missingSpreadLineCount($season);
        $gamesWithSpreadLine = (int) (array_sum(array_column($teams, 'games_with_line')) / 2);
        $finalGames = $gamesWithSpreadLine + $missingSpreadLines;

        $report = [
            'summary' => [
                'season' => $season,
                'final_games' => $finalGames,
                'games_with_line' => $gamesWithSpreadLine,
                'missing_line_count' => $missingSpreadLines,
            ],
            'season' => $season,
            'final_games' => $finalGames,
            'games_with_spread_line' => $gamesWithSpreadLine,
            'games_missing_spread_line' => $missingSpreadLines,
            'teams' => $teams,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('WNBA Team ATS Report');
        $this->line("Season: {$season}");
        $this->line("Final games: {$finalGames}");
        $this->line("Games with spread line: {$gamesWithSpreadLine}");
        $this->line("Games missing spread line: {$missingSpreadLines}");
        $this->newLine();

        $this->table(
            ['Team', 'ATS', 'ATS %', 'Home ATS', 'Away ATS', 'Avg Cover Margin', 'Games', 'Missing Lines'],
            collect($teams)->map(fn (array $team): array => [
                $team['team'],
                $team['ats'],
                $team['ats_pct_ex_pushes'] !== null ? number_format((float) $team['ats_pct_ex_pushes'], 1).'%' : 'n/a',
                $team['home_ats'],
                $team['away_ats'],
                $team['avg_cover_margin'] !== null ? $this->signed((float) $team['avg_cover_margin'], 2) : 'n/a',
                (string) $team['games_with_line'],
                (string) $team['missing_line_games'],
            ])->all()
        );

        return self::SUCCESS;
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }
}
