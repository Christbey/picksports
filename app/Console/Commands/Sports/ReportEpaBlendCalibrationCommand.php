<?php

namespace App\Console\Commands\Sports;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportEpaBlendCalibrationCommand extends Command
{
    protected $signature = 'sports:report-epa-blend-calibration
        {sport : One of nfl,nba,cbb,wcbb}
        {--season= : Filter by season}';

    protected $description = 'Report prediction calibration split by true-EPA blend applied vs not applied';

    /**
     * @var array<string,array{predictions:string,games:string}>
     */
    private const SPORT_TABLES = [
        'nfl' => ['predictions' => 'nfl_predictions', 'games' => 'nfl_games'],
        'nba' => ['predictions' => 'nba_predictions', 'games' => 'nba_games'],
        'cbb' => ['predictions' => 'cbb_predictions', 'games' => 'cbb_games'],
        'wcbb' => ['predictions' => 'wcbb_predictions', 'games' => 'wcbb_games'],
    ];

    public function handle(): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        if (! isset(self::SPORT_TABLES[$sport])) {
            $allowed = implode(', ', array_keys(self::SPORT_TABLES));
            $this->error("Unsupported sport '{$sport}'. Allowed: {$allowed}");

            return self::FAILURE;
        }

        $season = $this->option('season');
        $tables = self::SPORT_TABLES[$sport];

        $query = DB::table($tables['predictions'].' as p')
            ->join($tables['games'].' as g', 'g.id', '=', 'p.game_id')
            ->whereNotNull('p.graded_at')
            ->whereNotNull('p.predicted_spread')
            ->whereNotNull('p.actual_spread')
            ->select([
                'p.id',
                'p.predicted_spread',
                'p.actual_spread',
                'p.predicted_total',
                'p.actual_total',
                'p.is_winner_correct',
                'p.model_metadata',
                'g.season',
            ]);

        if ($season !== null && $season !== '') {
            $query->where('g.season', (int) $season);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->warn('No graded predictions found for the selected scope.');

            return self::SUCCESS;
        }

        $groups = [
            'all' => [],
            'true_epa_applied' => [],
            'true_epa_not_applied' => [],
        ];

        foreach ($rows as $row) {
            $groups['all'][] = $row;

            if ($this->isTrueEpaApplied($row->model_metadata)) {
                $groups['true_epa_applied'][] = $row;
            } else {
                $groups['true_epa_not_applied'][] = $row;
            }
        }

        $this->line(strtoupper($sport).' EPA Blend Calibration Report');
        $this->line('----------------------------------------');
        $this->line('Scope: '.($season ? "season {$season}" : 'all seasons'));
        $this->line('Total graded predictions: '.count($groups['all']));
        $this->newLine();

        $tableRows = [];
        foreach ($groups as $label => $groupRows) {
            $stats = $this->summarizeGroup($groupRows);
            $tableRows[] = [
                $label,
                $stats['count'],
                $stats['spread_mae'],
                $stats['total_mae'],
                $stats['winner_accuracy'],
            ];
        }

        $this->table(
            ['Group', 'Count', 'Spread MAE', 'Total MAE', 'Winner Acc %'],
            $tableRows
        );

        $applied = $this->summarizeGroup($groups['true_epa_applied']);
        $notApplied = $this->summarizeGroup($groups['true_epa_not_applied']);
        $this->newLine();
        $this->line('Observational deltas (applied - not_applied):');
        $this->line('Spread MAE delta: '.$this->formatDelta($applied['spread_mae_raw'], $notApplied['spread_mae_raw']));
        $this->line('Total MAE delta: '.$this->formatDelta($applied['total_mae_raw'], $notApplied['total_mae_raw']));
        $this->line('Winner accuracy delta: '.$this->formatDelta($applied['winner_accuracy_raw'], $notApplied['winner_accuracy_raw'], true));
        $this->line('Note: This is observational and not a causal A/B split.');

        return self::SUCCESS;
    }

    /**
     * @return array{count:int,spread_mae:string,total_mae:string,winner_accuracy:string,spread_mae_raw:?float,total_mae_raw:?float,winner_accuracy_raw:?float}
     */
    private function summarizeGroup(array $rows): array
    {
        $count = count($rows);
        if ($count === 0) {
            return [
                'count' => 0,
                'spread_mae' => 'n/a',
                'total_mae' => 'n/a',
                'winner_accuracy' => 'n/a',
                'spread_mae_raw' => null,
                'total_mae_raw' => null,
                'winner_accuracy_raw' => null,
            ];
        }

        $spreadErrors = [];
        $totalErrors = [];
        $winnerCorrect = 0;

        foreach ($rows as $row) {
            $spreadErrors[] = abs((float) $row->actual_spread - (float) $row->predicted_spread);

            if ($row->predicted_total !== null && $row->actual_total !== null) {
                $totalErrors[] = abs((float) $row->actual_total - (float) $row->predicted_total);
            }

            $winnerCorrect += (int) ((bool) $row->is_winner_correct);
        }

        $spreadMae = array_sum($spreadErrors) / max(1, count($spreadErrors));
        $totalMae = $totalErrors === [] ? null : (array_sum($totalErrors) / count($totalErrors));
        $winnerAcc = ($winnerCorrect / $count) * 100.0;

        return [
            'count' => $count,
            'spread_mae' => number_format($spreadMae, 3),
            'total_mae' => $totalMae === null ? 'n/a' : number_format($totalMae, 3),
            'winner_accuracy' => number_format($winnerAcc, 2),
            'spread_mae_raw' => $spreadMae,
            'total_mae_raw' => $totalMae,
            'winner_accuracy_raw' => $winnerAcc,
        ];
    }

    private function isTrueEpaApplied(mixed $metadata): bool
    {
        if (! is_string($metadata) || $metadata === '') {
            return false;
        }

        $decoded = json_decode($metadata, true);
        if (! is_array($decoded)) {
            return false;
        }

        $trueEpa = $decoded['true_epa'] ?? null;
        if (! is_array($trueEpa)) {
            return false;
        }

        if (array_key_exists('true_epa_applied', $trueEpa)) {
            return (bool) $trueEpa['true_epa_applied'];
        }

        if (array_key_exists('applied', $trueEpa)) {
            return (bool) $trueEpa['applied'];
        }

        return false;
    }

    private function formatDelta(?float $a, ?float $b, bool $asPercent = false): string
    {
        if ($a === null || $b === null) {
            return 'n/a';
        }

        $delta = $a - $b;
        $formatted = number_format($delta, $asPercent ? 2 : 3);

        return ($delta > 0 ? '+' : '').$formatted;
    }
}
