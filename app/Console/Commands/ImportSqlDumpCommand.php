<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportSqlDumpCommand extends Command
{
    protected $signature = 'db:import-sql {file : The path to the SQL dump file}';

    protected $description = '[Retired] Legacy SQL dump importer';

    public function handle(): int
    {
        $this->error('db:import-sql is retired. It performs no database or file operations. For an authorized trusted MySQL import into a new schema, use scripts/import-mysql-dump.php after reviewing docs/operations/database-access.md.');

        return Command::FAILURE;
    }
}
