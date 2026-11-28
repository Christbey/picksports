<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportFromSQLiteCommand extends Command
{
    protected $signature = 'db:import-from-sqlite {--tables=* : Specific tables to import}';

    protected $description = '[Retired] Legacy SQLite-to-MySQL data importer';

    public function handle(): int
    {
        $this->error('db:import-from-sqlite is retired. It performs no database or file operations. SQLite data migration requires a separately reviewed source/schema migration workflow.');

        return Command::FAILURE;
    }
}
