<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSqlDumpCommand extends Command
{
    protected $signature = 'db:import-sql {file : The path to the SQL dump file}';

    protected $description = 'Import a SQL dump file into the database';

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("Importing SQL dump from: {$filePath}");

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}");

        if ($driver === 'sqlite') {
            $this->error('This command does not support SQLite databases.');
            $this->error('Please use MySQL or another supported database for SQL dump imports.');

            return Command::FAILURE;
        }

        if ($driver === 'mysql') {
            return $this->importMysqlDump($filePath, $connection);
        }

        $this->error("Unsupported database driver: {$driver}");

        return Command::FAILURE;
    }

    protected function importMysqlDump(string $filePath, array $connection): int
    {
        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? 3306;

        $this->info('Starting MySQL import...');

        $passwordArg = $password ? "-p'{$password}'" : '';

        $command = sprintf(
            'mysql -h %s -P %s -u %s %s %s < %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            $passwordArg,
            escapeshellarg($database),
            escapeshellarg($filePath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('Import failed with errors:');
            foreach ($output as $line) {
                $this->error($line);
            }

            return Command::FAILURE;
        }

        $this->info('SQL dump imported successfully!');

        return Command::SUCCESS;
    }
}
