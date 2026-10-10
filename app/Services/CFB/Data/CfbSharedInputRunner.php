<?php

namespace App\Services\CFB\Data;

use Illuminate\Support\Facades\Process;

/** Shared refreshes have large temporary graphs; do not retain them in the weekly orchestrator. */
class CfbSharedInputRunner
{
    public function run(string $command, array $arguments): array
    {
        $argv = [PHP_BINARY, '-d', 'memory_limit=192M', base_path('artisan'), $command];
        foreach ($arguments as $name => $value) {
            if ($value !== false && $value !== null) {
                $argv[] = $value === true ? $name : $name.'='.$value;
            }
        }
        $result = Process::path(base_path())->timeout(600)->run($argv);

        return ['exit_code' => $result->exitCode(), 'output' => trim($result->output().' '.$result->errorOutput())];
    }
}
