<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropCalibrationExperiment;
use Illuminate\Console\Command;

class CalibrateProspectivePropsCommand extends Command
{
    protected $signature = 'nfl:calibrate-prospective-props {dataset : Validated dataset JSON} {--output= : Offline report path}';

    protected $description = 'Run offline receiving prop calibration; never publish or alter production scoring';

    public function handle(NflPropCalibrationExperiment $experiment): int
    {
        $dataset = json_decode(file_get_contents($this->argument('dataset')), true, flags: JSON_THROW_ON_ERROR);
        if (($dataset['format'] ?? null) !== 'nfl-prop-prospective-dataset-v1' || ! is_array($dataset['rows'] ?? null)) {
            $this->error('A validated prospective dataset is required.');

            return self::FAILURE;
        }
        $json = json_encode($experiment->report($dataset), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($this->option('output')) {
            file_put_contents($this->option('output'), $json."\n");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
