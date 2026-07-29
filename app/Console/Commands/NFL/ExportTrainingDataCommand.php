<?php

namespace App\Console\Commands\NFL;

use App\Services\ML\CsvDataset;
use App\Services\ML\TrustedSnapshotDataset;
use Illuminate\Console\Command;

class ExportTrainingDataCommand extends Command
{
    protected $signature = 'nfl:export-training-data
        {--season= : Filter rows by season}
        {--from-season= : First season to include}
        {--to-season= : Last season to include}
        {--feature-version= : Require one exact feature schema version}
        {--path=storage/app/ml/nfl_training_data.csv : Output CSV path}
        {--limit=0 : Optional row limit}
        {--profile=elo-only : Historical reconstruction profile}
        {--include-unverified : Include rows without verified point-in-time lineage}';

    protected $description = 'Export one target-stable NFL training row per verified historical snapshot';

    public function handle(TrustedSnapshotDataset $dataset, CsvDataset $csv): int
    {
        $season = $this->nullableIntegerOption('season');
        $fromSeason = $this->nullableIntegerOption('from-season');
        $toSeason = $this->nullableIntegerOption('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            $this->error('Use either --season or --from-season/--to-season, not both.');

            return self::INVALID;
        }

        if ($fromSeason !== null && $toSeason !== null && $fromSeason > $toSeason) {
            $this->error('--from-season must be less than or equal to --to-season.');

            return self::INVALID;
        }

        $rows = $dataset->rows(
            sport: 'nfl',
            season: $season,
            strictPregame: ! (bool) $this->option('include-unverified'),
            historicalProfile: $this->option('profile') ? (string) $this->option('profile') : null,
            limit: max(0, (int) $this->option('limit')),
            fromSeason: $fromSeason,
            toSeason: $toSeason,
            featureVersion: $this->option('feature-version')
                ? (string) $this->option('feature-version')
                : null,
        );

        if ($rows->isEmpty()) {
            $this->warn('No NFL rows met the requested profile, provenance, and target requirements.');

            return self::SUCCESS;
        }

        $path = str_starts_with((string) $this->option('path'), '/')
            ? (string) $this->option('path')
            : base_path((string) $this->option('path'));
        $count = $csv->write($path, $rows);

        $this->info('NFL trusted training data exported.');
        $this->line("Rows: {$count}");
        $this->line('Profile: '.($this->option('profile') ?: 'all'));
        $this->line('Seasons: '.$this->seasonRangeLabel($season, $fromSeason, $toSeason));
        $this->line('Feature version: '.($this->option('feature-version') ?: 'all'));
        $this->line('Dataset SHA-256: '.hash_file('sha256', $path));
        $this->line("Path: {$path}");

        return self::SUCCESS;
    }

    private function nullableIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function seasonRangeLabel(?int $season, ?int $fromSeason, ?int $toSeason): string
    {
        if ($season !== null) {
            return (string) $season;
        }

        return ($fromSeason ?? 'first').' through '.($toSeason ?? 'latest');
    }
}
