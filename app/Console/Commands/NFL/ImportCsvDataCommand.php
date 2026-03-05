<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportCsvDataCommand extends Command
{
    protected $signature = 'nfl:import-csv-data
                            {--teams=/Users/bey/nfl_teams.csv : Path to nfl_teams.csv}
                            {--games=/Users/bey/nfl_games.csv : Path to nfl_games.csv}
                            {--players=/Users/bey/nfl_players.csv : Path to nfl_players.csv}
                            {--plays=/Users/bey/nfl_plays.csv : Path to nfl_plays.csv}
                            {--player-stats=/Users/bey/nfl_player_stats.csv : Path to nfl_player_stats.csv}
                            {--team-stats=/Users/bey/nfl_team_stats.csv : Path to nfl_team_stats.csv}
                            {--predictions=/Users/bey/nfl_predictions.csv : Path to nfl_predictions.csv}
                            {--append : Keep existing rows and upsert by id instead of truncating first}
                            {--chunk=500 : Number of rows per upsert chunk}';

    protected $description = 'Import NFL CSV exports into nfl_* tables';

    /**
     * @var array<string, array{table: string, option: string}>
     */
    protected array $datasets = [
        'teams' => ['table' => 'nfl_teams', 'option' => 'teams'],
        'games' => ['table' => 'nfl_games', 'option' => 'games'],
        'players' => ['table' => 'nfl_players', 'option' => 'players'],
        'plays' => ['table' => 'nfl_plays', 'option' => 'plays'],
        'player_stats' => ['table' => 'nfl_player_stats', 'option' => 'player-stats'],
        'team_stats' => ['table' => 'nfl_team_stats', 'option' => 'team-stats'],
        'predictions' => ['table' => 'nfl_predictions', 'option' => 'predictions'],
    ];

    public function handle(): int
    {
        $chunkSize = max((int) $this->option('chunk'), 1);
        $append = (bool) $this->option('append');

        $paths = [];
        foreach ($this->datasets as $dataset => $config) {
            $paths[$dataset] = (string) $this->option($config['option']);
        }

        foreach ($paths as $dataset => $path) {
            if (! is_file($path)) {
                $this->error("Missing file for {$dataset}: {$path}");

                return self::FAILURE;
            }
        }

        $this->info('Starting NFL CSV import');
        $this->line($append ? 'Mode: append/upsert by id' : 'Mode: replace existing data (truncate + import)');
        $usesTransaction = $append;

        try {
            if ($usesTransaction) {
                DB::beginTransaction();
            }

            if (! $append) {
                $this->truncateTargetTables();
            }

            foreach ($this->datasets as $dataset => $config) {
                $path = $paths[$dataset];
                $table = $config['table'];
                $imported = $this->importCsvIntoTable($table, $path, $chunkSize);
                $this->info("{$table}: imported/upserted {$imported} row(s)");
            }

            if ($usesTransaction) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if ($usesTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('NFL CSV import complete');

        return self::SUCCESS;
    }

    protected function truncateTargetTables(): void
    {
        $tables = [
            'nfl_predictions',
            'nfl_player_stats',
            'nfl_team_stats',
            'nfl_plays',
            'nfl_games',
            'nfl_players',
            'nfl_teams',
        ];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
                $this->line("Truncated {$table}");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    protected function importCsvIntoTable(string $table, string $path, int $chunkSize): int
    {
        $handle = fopen($path, 'r');

        if (! is_resource($handle)) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        try {
            $rawHeader = fgetcsv($handle);
            if (! is_array($rawHeader) || $rawHeader === []) {
                return 0;
            }

            $header = array_map(fn ($col) => trim((string) $col), $rawHeader);
            if (isset($header[0])) {
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            }

            $dbColumns = Schema::getColumnListing($table);
            $dbColumnLookup = array_fill_keys($dbColumns, true);

            $columnIndexes = [];
            foreach ($header as $index => $column) {
                if ($column === '#' || $column === '') {
                    continue;
                }

                if (isset($dbColumnLookup[$column])) {
                    $columnIndexes[$index] = $column;
                }
            }

            if ($columnIndexes === []) {
                return 0;
            }

            $rows = [];
            $total = 0;

            while (($csvRow = fgetcsv($handle)) !== false) {
                if (! is_array($csvRow)) {
                    continue;
                }

                $row = [];
                foreach ($columnIndexes as $index => $column) {
                    $value = $csvRow[$index] ?? null;
                    $row[$column] = $this->normalizeValue($column, $value);
                }

                if (! isset($row['id']) || $row['id'] === null) {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) >= $chunkSize) {
                    $this->upsertChunk($table, $rows);
                    $total += count($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                $this->upsertChunk($table, $rows);
                $total += count($rows);
            }

            return $total;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertChunk(string $table, array $rows): void
    {
        $updateColumns = array_values(array_filter(array_keys($rows[0]), fn ($col) => $col !== 'id'));
        DB::table($table)->upsert($rows, ['id'], $updateColumns);
    }

    protected function normalizeValue(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        if ($column === 'game_time') {
            return $this->normalizeGameTime($trimmed);
        }

        if (is_numeric($trimmed) && ctype_digit(ltrim($trimmed, '-'))) {
            return (int) $trimmed;
        }

        return $trimmed;
    }

    protected function normalizeGameTime(string $value): string
    {
        // Convert values like "8:40p" and "1:00a" into SQL time format.
        if (preg_match('/^\d{1,2}:\d{2}[ap]$/i', $value) === 1) {
            $meridiem = str_ends_with(strtolower($value), 'p') ? 'pm' : 'am';
            $base = substr($value, 0, -1);
            $timestamp = strtotime($base.$meridiem);

            if ($timestamp !== false) {
                return date('H:i:s', $timestamp);
            }
        }

        return $value;
    }
}
