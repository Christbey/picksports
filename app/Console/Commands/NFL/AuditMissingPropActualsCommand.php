<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropMissingActualAudit;
use Illuminate\Console\Command;

class AuditMissingPropActualsCommand extends Command
{
    protected $signature = 'nfl:audit-missing-prop-actuals {--season= : Required season} {--output= : Aggregate JSON path}';

    protected $description = 'Trace missing frozen prop outcomes to stored identity and source coverage without grading or refreshing';

    public function handle(NflPropMissingActualAudit $audit): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2000 || $season > now()->year) {
            $this->error('Supply a valid season.');

            return self::FAILURE;
        }
        $json = json_encode($audit->report($season), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($path = $this->option('output')) {
            file_put_contents($path, $json."\n");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
