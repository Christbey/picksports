<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropProspectiveDataset;
use Illuminate\Console\Command;

class AuditProspectivePropsCommand extends Command
{
    protected $signature = 'nfl:audit-prospective-props {--season= : Required NFL season} {--output= : Aggregate JSON path} {--dataset-output= : Optional private deidentified dataset JSON path}';

    protected $description = 'Validate frozen NFL prop evidence and classify pending outcomes without changing records';

    public function handle(NflPropProspectiveDataset $builder): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2000 || $season > now()->year) {
            $this->error('Supply a valid season.');

            return self::FAILURE;
        }
        $dataset = $builder->build($season);
        if ($path = $this->option('dataset-output')) {
            // Create exclusively with private permissions before writing any row data.
            $old = umask(0077);
            try {
                $file = fopen($path, 'x');
                if ($file === false) {
                    throw new \RuntimeException('Cannot create new private dataset file.');
                }
                fwrite($file, json_encode($dataset, JSON_THROW_ON_ERROR)."\n");
                fclose($file);
            } finally {
                umask($old);
            }
        }
        $dataset['eligible_labeled_rows'] = count($dataset['rows']);
        unset($dataset['rows']);
        $json = json_encode($dataset, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($this->option('output')) {
            file_put_contents($this->option('output'), $json."\n");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
