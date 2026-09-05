<?php

namespace App\Console\Commands\Database;

use App\Services\Database\DatabaseFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class FingerprintCommand extends Command
{
    protected $signature = 'db:fingerprint
        {--connection= : Database connection to inspect}
        {--output= : Write JSON to this path instead of stdout}
        {--exact-counts : Count every table exactly; this can be expensive on production data}
        {--pretty : Pretty-print JSON output}';

    protected $description = 'Capture a read-only database fingerprint for migration verification.';

    public function handle(DatabaseFingerprint $fingerprint): int
    {
        try {
            $payload = $fingerprint->capture(
                $this->option('connection') ?: null,
                (bool) $this->option('exact-counts'),
            );
            $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
            if ((bool) $this->option('pretty')) {
                $flags |= JSON_PRETTY_PRINT;
            }

            $json = json_encode($payload, $flags).PHP_EOL;
            $output = $this->option('output');

            if (! is_string($output) || $output === '') {
                $this->output->write($json);

                return self::SUCCESS;
            }

            $path = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : base_path($output);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
            $this->info(sprintf('Database fingerprint written to %s.', $path));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(sprintf('Unable to fingerprint database: %s', $exception->getMessage()));

            return self::FAILURE;
        }
    }
}
