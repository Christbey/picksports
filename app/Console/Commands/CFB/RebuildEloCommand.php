<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Elo\EloRebuildService;
use Illuminate\Console\Command;

class RebuildEloCommand extends Command
{
    protected $signature = 'cfb:rebuild-elo {--activate= : Activate a reviewed candidate ID, preserving earlier rating versions}';

    protected $description = 'Build a deterministic isolated CFB Elo candidate and compare ratings; explicit activation only';

    public function handle(EloRebuildService $service): int
    {
        try {
            $result = $this->option('activate')
                ? $service->activate((int) $this->option('activate'))
                : $service->build();
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
