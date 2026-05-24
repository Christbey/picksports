<?php

namespace App\Console\Commands\Sports;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportPlayerPropsCalibrationCommand extends Command
{
    protected $signature = 'sports:report-player-props-calibration
        {sport? : One of nba,cbb,wnba,nfl,mlb (omit for all)}
        {--season= : Filter by season}
        {--min-sample=30 : Minimum sample per market to include in market table}';

    protected $description = 'Report player-props calibration metrics (Brier, log-loss, ECE) overall and by market';

    /**
     * @var array<string,array{prop_table:string,game_table:string}>
     */
    private const TABLES = [
        'nba' => ['prop_table' => 'nba_player_props', 'game_table' => 'nba_games'],
        'cbb' => ['prop_table' => 'cbb_player_props', 'game_table' => 'cbb_games'],
        'wnba' => ['prop_table' => 'wnba_player_props', 'game_table' => 'wnba_games'],
        'nfl' => ['prop_table' => 'nfl_player_props', 'game_table' => 'nfl_games'],
        'mlb' => ['prop_table' => 'mlb_player_props', 'game_table' => 'mlb_games'],
    ];

    public function handle(): int
    {
        $sportArg = $this->argument('sport');
        $seasonArg = $this->option('season');
        $minSample = max(1, (int) $this->option('min-sample'));
        $season = ($seasonArg === null || $seasonArg === '') ? null : (int) $seasonArg;

        $sports = $this->resolveSports($sportArg);
        if ($sports === []) {
            $allowed = implode(', ', array_keys(self::TABLES));
            $this->error("Unsupported sport '{$sportArg}'. Allowed: {$allowed}");

            return self::FAILURE;
        }

        foreach ($sports as $index => $sport) {
            if ($index > 0) {
                $this->newLine();
            }

            $this->reportForSport($sport, $season, $minSample);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveSports(?string $sport): array
    {
        if ($sport === null || trim($sport) === '') {
            return array_keys(self::TABLES);
        }

        $normalized = strtolower(trim($sport));

        return isset(self::TABLES[$normalized]) ? [$normalized] : [];
    }

    private function reportForSport(string $sport, ?int $season, int $minSample): void
    {
        $rows = $this->baseQuery($sport, $season)->get();

        $scope = $season === null ? 'all seasons' : "season {$season}";
        $this->line(strtoupper($sport)." Player Props Calibration ({$scope})");
        $this->line(str_repeat('-', 54));

        if ($rows->isEmpty()) {
            $this->warn('No graded props with predicted_over_probability in selected scope.');

            return;
        }

        $overall = $this->summarize($rows);

        $this->table(
            ['Sample', 'Brier', 'LogLoss', 'ECE(10-bin)', 'MAE Prob'],
            [[
                (string) $overall['count'],
                $this->fmt($overall['brier']),
                $this->fmt($overall['logloss']),
                $this->fmt($overall['ece']),
                $this->fmt($overall['mae_prob']),
            ]]
        );

        $marketRows = $rows
            ->groupBy(fn ($r) => (string) $r->market)
            ->map(function (Collection $group, string $market): array {
                $summary = $this->summarize($group);

                return [
                    'market' => $market,
                    'count' => $summary['count'],
                    'brier' => $summary['brier'],
                    'logloss' => $summary['logloss'],
                    'ece' => $summary['ece'],
                    'mae_prob' => $summary['mae_prob'],
                ];
            })
            ->filter(fn (array $s) => $s['count'] >= $minSample)
            ->sortByDesc('count')
            ->values();

        if ($marketRows->isEmpty()) {
            $this->warn("No markets met min sample {$minSample}.");

            return;
        }

        $this->newLine();
        $this->line("By market (min sample {$minSample})");
        $this->table(
            ['Market', 'Sample', 'Brier', 'LogLoss', 'ECE(10-bin)', 'MAE Prob'],
            $marketRows->map(fn (array $r) => [
                $r['market'],
                (string) $r['count'],
                $this->fmt($r['brier']),
                $this->fmt($r['logloss']),
                $this->fmt($r['ece']),
                $this->fmt($r['mae_prob']),
            ])->all()
        );
    }

    private function baseQuery(string $sport, ?int $season)
    {
        $tables = self::TABLES[$sport];

        $query = DB::table($tables['prop_table'].' as p')
            ->join($tables['game_table'].' as g', 'g.id', '=', 'p.game_id')
            ->whereNotNull('p.graded_at')
            ->whereNotNull('p.hit_over')
            ->whereNotNull('p.predicted_over_probability')
            ->select([
                'p.market',
                'p.hit_over',
                'p.predicted_over_probability',
            ]);

        if ($season !== null) {
            $query->where('g.season', $season);
        }

        return $query;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{count:int,brier:float,logloss:float,ece:float,mae_prob:float}
     */
    private function summarize(Collection $rows): array
    {
        $count = $rows->count();
        if ($count === 0) {
            return ['count' => 0, 'brier' => 0.0, 'logloss' => 0.0, 'ece' => 0.0, 'mae_prob' => 0.0];
        }

        $brierTotal = 0.0;
        $loglossTotal = 0.0;
        $maeTotal = 0.0;

        $bins = array_fill(0, 10, [
            'count' => 0,
            'pred_sum' => 0.0,
            'actual_sum' => 0.0,
        ]);

        foreach ($rows as $row) {
            $pred = max(0.001, min(0.999, ((float) $row->predicted_over_probability) / 100));
            $actual = (bool) $row->hit_over ? 1.0 : 0.0;

            $diff = $pred - $actual;
            $brierTotal += ($diff * $diff);
            $maeTotal += abs($diff);
            $loglossTotal += -(($actual * log($pred)) + ((1.0 - $actual) * log(1.0 - $pred)));

            $bin = min(9, max(0, (int) floor($pred * 10)));
            $bins[$bin]['count']++;
            $bins[$bin]['pred_sum'] += $pred;
            $bins[$bin]['actual_sum'] += $actual;
        }

        $ece = 0.0;
        foreach ($bins as $bin) {
            if ($bin['count'] === 0) {
                continue;
            }

            $avgPred = $bin['pred_sum'] / $bin['count'];
            $avgActual = $bin['actual_sum'] / $bin['count'];
            $ece += ($bin['count'] / $count) * abs($avgActual - $avgPred);
        }

        return [
            'count' => $count,
            'brier' => $brierTotal / $count,
            'logloss' => $loglossTotal / $count,
            'ece' => $ece,
            'mae_prob' => $maeTotal / $count,
        ];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 4);
    }
}
