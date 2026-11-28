<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflCalibrationReport;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReportCalibrationCommand extends Command
{
    protected $signature = 'nfl:report-calibration {--season= : NFL season, defaults to current year} {--model-version= : Exact model version}';

    protected $description = 'Report NFL prospective calibration evidence without changing forecasts or settings';

    public function handle(NflCalibrationReport $report): int
    {
        $season = $this->option('season') ?? now()->year;
        if (filter_var($season, FILTER_VALIDATE_INT) === false || (int) $season < 2000 || (int) $season > 2100) {
            $this->error('Season must be an integer between 2000 and 2100.');

            return self::FAILURE;
        }
        $version = $this->option('model-version') ?? NflModelVersion::current();
        if (trim($version) === '') {
            $this->error('Model version cannot be empty.');

            return self::FAILURE;
        }
        $this->line(json_encode($report->build((int) $season, $version, CarbonImmutable::now()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
