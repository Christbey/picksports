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
        $provided = $this->input->hasParameterOption('--activate', true);
        $candidateId = $provided ? filter_var($this->option('activate'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($provided && $candidateId === false) {
            $this->error('The activate option requires a positive integer candidate ID.');

            return self::FAILURE;
        }
        try {
            $result = $provided
                ? $service->activate($candidateId)
                : $service->build();
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
