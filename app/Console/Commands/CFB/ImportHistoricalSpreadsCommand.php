<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\CfbHistoricalSpreadImporter;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;

class ImportHistoricalSpreadsCommand extends Command
{
    protected $signature = 'cfb:import-historical-spreads {--season= : Required season}
        {--season-type=regular : regular or postseason} {--file= : Use a saved CFBD JSON response}
        {--dry-run : Validate without database or archive writes}';

    protected $description = 'Import auditable retrospective CFBD spread pairs for existing completed games';

    public function handle(CollegeFootballDataService $source, CfbHistoricalSpreadImporter $importer, ProviderSourceStorage $storage): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $type = $this->option('season-type');
        if (! $season || $season < 1900 || $season > (int) now()->year || ! in_array($type, ['regular', 'postseason'], true)) {
            $this->error('Provide a valid --season and --season-type=regular|postseason.');

            return self::FAILURE;
        }
        $temporary = null;
        try {
            $file = $this->option('file');
            $rows = $file ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)
                : $source->get('/lines', ['year' => $season, 'seasonType' => $type]);
            if (! is_array($rows) || ! array_is_list($rows) || array_filter($rows, fn ($row) => ! is_array($row))) {
                throw new \RuntimeException('Expected a CFBD array of game records.');
            }
            $sourceFile = null;
            $started = now();
            if (! $this->option('dry-run')) {
                $temporary = tempnam(sys_get_temp_dir(), 'cfb-lines-');
                file_put_contents($temporary, json_encode($rows, JSON_THROW_ON_ERROR));
                $sourceFile = $storage->archive('cfbd', 'cfb_historical_lines', $temporary,
                    ['season' => $season, 'season_type' => $type, 'endpoint' => '/lines', 'retrieved_at' => $started->toIso8601String()]);
            }
            $report = $importer->import($rows, $season, (bool) $this->option('dry-run'), $sourceFile?->id);
            if ($sourceFile) {
                $storage->recordImport($sourceFile, count($rows), $report['matched_games'], count($rows) - $report['matched_games'],
                    $started, ['season_type' => $type, 'report' => $report]);
            }
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
