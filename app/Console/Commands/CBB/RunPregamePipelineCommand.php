<?php

namespace App\Console\Commands\CBB;

use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\PipelineCommandRunner;
use App\Support\BasketballSeason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'cbb:run-pregame-pipeline {--season=} {--days-forward=2} {--detail-limit=25}';

    protected $description = 'Run bounded CBB ingest, grading, ratings, metrics, and pregame generation in dependency order';

    public function handle(PipelineCommandRunner $runner): int
    {
        $lock = Cache::lock('cbb-pregame-pipeline', 1800);
        if (! $lock->get()) {
            $this->warn('CBB pregame pipeline is already running.');

            return self::FAILURE;
        }
        try {
            $season = (int) ($this->option('season') ?: BasketballSeason::forDate());
            $days = min(7, max(0, (int) $this->option('days-forward')));
            $steps = [
                ['espn:sync-cbb-games-scoreboard', ['--sync' => true, '--from-date' => now('America/Chicago')->toDateString(), '--to-date' => now('America/Chicago')->addDays($days)->toDateString()]],
                ['espn:sync-cbb-game-details', ['--sync' => true, '--lookback-days' => 7, '--days-forward' => 0, '--limit' => min(100, max(1, (int) $this->option('detail-limit'))), '--latest' => true]],
                ['cbb:grade-predictions', ['--season' => $season]],
                ['cbb:calculate-elo', ['--season' => $season]],
                ['cbb:calculate-team-metrics', ['--season' => $season]],
                [app(PredictionDeliverySource::class)->legacyWriterRetired('cbb') ? 'cbb:generate-canonical-predictions' : 'cbb:generate-predictions', ['--season' => $season]],
            ];
            if (config('prediction_lifecycle.canonical_pipeline.cbb') && ! app(PredictionDeliverySource::class)->legacyWriterRetired('cbb')) {
                $steps[] = ['cbb:generate-canonical-predictions', ['--season' => $season]];
            }
            $detailFailure = false;
            foreach ($steps as [$command, $arguments]) {
                $this->line($command);
                try {
                    $exitCode = $runner->call($command, [...$arguments, '--no-interaction' => true]);
                } catch (Throwable $exception) {
                    report($exception);
                    $exitCode = self::FAILURE;
                }
                if ($exitCode !== self::SUCCESS) {
                    $this->error('CBB pipeline step failed: '.$command);
                    if ($command === 'espn:sync-cbb-game-details') {
                        $detailFailure = true;

                        continue;
                    }

                    return self::FAILURE;
                }
            }

            return $detailFailure ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
