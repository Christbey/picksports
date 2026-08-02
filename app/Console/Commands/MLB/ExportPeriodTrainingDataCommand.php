<?php

namespace App\Console\Commands\MLB;

use App\Services\ML\CsvDataset;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use Illuminate\Console\Command;

class ExportPeriodTrainingDataCommand extends Command
{
    protected $signature = 'mlb:export-period-training-data
        {--from-season=2021 : First training season}
        {--to-season= : Last training season}
        {--path=storage/app/ml/mlb_period_training_data.csv : Output CSV path}';

    protected $description = 'Export point-in-time-safe MLB F3 and F5 matchup features and targets';

    public function handle(MlbPeriodFeatureBuilder $features, CsvDataset $csv): int
    {
        $from = (int) $this->option('from-season');
        $to = (int) ($this->option('to-season') ?: now()->year);
        if ($from > $to) {
            $this->error('The first season must be on or before the last season.');

            return self::FAILURE;
        }

        $rows = $features->historicalRows(range($from, $to));
        if ($rows->isEmpty()) {
            $this->error('No completed MLB games with usable F3/F5 line scores were found.');

            return self::FAILURE;
        }

        $path = (string) $this->option('path');
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        $csv->write($absolute, $rows);

        $this->info('MLB period training data exported.');
        $this->line('Rows: '.$rows->count());
        $this->line('Games: '.$rows->pluck('game_id')->unique()->count());
        $this->line('Dataset SHA-256: '.hash_file('sha256', $absolute));
        $this->line('Path: '.$absolute);

        return self::SUCCESS;
    }
}
