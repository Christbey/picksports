<?php

namespace App\Console\Commands\CFB;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class ReportPostgameExpectancyCommand extends Command
{
    protected $signature = 'cfb:report-postgame-expectancy {--artifact=} {--manifest=} {--output=}';

    protected $description = 'Evaluate observed efficiency after games without changing forecasts or fetching providers';

    public function handle(): int
    {
        if (! is_file((string) $this->option('artifact')) || ! is_file((string) $this->option('manifest'))
            || ! $this->option('output') || file_exists($this->option('output'))) {
            $this->error('Provide existing artifact and manifest files and a new output path.');

            return self::FAILURE;
        }
        $result = Process::timeout(600)->run([config('cfb.scoring.python', 'python3'), base_path('ml/cfb/postgame.py'),
            '--artifact', $this->option('artifact'), '--manifest', $this->option('manifest'), '--output', $this->option('output')]);
        if (! $result->successful()) {
            $this->error($result->errorOutput());

            return self::FAILURE;
        }
        $this->line(trim($result->output()));

        return self::SUCCESS;
    }
}
