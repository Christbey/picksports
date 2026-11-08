<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledCommand;
use Illuminate\Console\Command;

class DispatchScheduledCommand extends Command
{
    protected $signature = 'ops:dispatch-scheduled {command_line} {--name=} {--heartbeat-url=}';

    protected $description = 'Dispatch scheduled work to existing workers without running it on web compute';

    public function handle(): int
    {
        $command = (string) $this->argument('command_line');
        if (str_starts_with($command, 'ops:dispatch-scheduled')) {
            $this->error('Recursive scheduled dispatch is not supported.');

            return self::FAILURE;
        }
        RunScheduledCommand::dispatch($command, (string) $this->option('name'), $this->option('heartbeat-url'));

        return self::SUCCESS;
    }
}
