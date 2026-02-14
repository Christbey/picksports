<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportFromSQLiteCommand extends Command
{
    protected $signature = 'db:import-from-sqlite {--tables=* : Specific tables to import}';

    protected $description = 'Import data from SQLite database to MySQL database';

    public function handle(): int
    {
        $this->info('Starting SQLite to MySQL import...');

        // Configure SQLite connection with absolute path
        config(['database.connections.sqlite.database' => database_path('database.sqlite')]);
        DB::purge('sqlite');

        // Get all tables from SQLite
        $tables = DB::connection('sqlite')->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations' ORDER BY name"
        );

        $tablesToImport = $this->option('tables') ?: array_map(fn ($t) => $t->name, $tables);

        if (empty($tablesToImport)) {
            $this->error('No tables to import');

            return Command::FAILURE;
        }

        $this->info('Found '.count($tablesToImport).' tables to import');

        foreach ($tablesToImport as $tableName) {
            $this->importTable($tableName);
        }

        $this->info('Import completed successfully!');

        return Command::SUCCESS;
    }

    protected function importTable(string $table): void
    {
        $this->info("Importing table: {$table}");

        // Count rows in source
        $count = DB::connection('sqlite')->table($table)->count();

        if ($count === 0) {
            $this->warn("  Table {$table} is empty, skipping");

            return;
        }

        $this->info("  Found {$count} rows");

        // Disable foreign key checks for faster import
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=0');

        $imported = 0;
        $chunkSize = 500;

        DB::connection('sqlite')->table($table)->orderBy('id')->chunk($chunkSize, function ($rows) use ($table, &$imported) {
            $data = [];

            foreach ($rows as $row) {
                $data[] = (array) $row;
            }

            if (! empty($data)) {
                DB::connection('mysql')->table($table)->insert($data);
                $imported += count($data);
                $this->info("  Imported {$imported} rows...");
            }
        });

        // Re-enable foreign key checks
        DB::connection('mysql')->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->info("  ✓ Completed {$table}: {$imported} rows imported");
    }
}
