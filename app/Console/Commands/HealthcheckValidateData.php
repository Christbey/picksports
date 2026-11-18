<?php

namespace App\Console\Commands;

use App\Actions\Validation\SportValidator;
use App\Models\ValidationRun;
use App\Services\Validation\ValidationRegressionAlertService;
use App\Services\Validation\ValidationReviewSummaryService;
use App\Services\Validation\ValidationRunRecorder;
use App\Support\HealthcheckOutcome;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HealthcheckValidateData extends Command
{
    protected $signature = 'healthcheck:validate-data
        {--report-only : Record unhealthy findings without failing successful execution}
        {--sport= : Specific sport to validate (mlb, nba, nfl, cbb, cfb, wcbb, wnba)}
        {--if-stale= : Skip when the latest completed validation is younger than this many minutes}
        {--skip-ai-summary : Use the local summary without calling an AI provider}
        {--skip-alerts : Do not send regression alerts}
        {--scope=full : Validation scope to run: full or data}
        {--data-only : Shortcut for --scope=data; validates source data freshness/completeness without derived prediction or AI readiness checks}';

    protected $description = 'Run deep data validation checks across sports';

    public function __construct(
        private readonly SportValidator $sportValidator,
        private readonly ValidationReviewSummaryService $validationReviewSummaryService,
        private readonly ValidationRegressionAlertService $validationRegressionAlertService,
        private readonly ValidationRunRecorder $recorder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->option('sport');
        if ($sport !== null && ! in_array($sport, SportCatalog::ALL, true)) {
            $this->error('Invalid --sport value. Supported sports: '.implode(', ', SportCatalog::ALL).'.');

            return self::FAILURE;
        }
        $scope = $this->validationScope();
        if ($scope === null) {
            return self::FAILURE;
        }
        $minutes = $this->option('if-stale') === null ? null : filter_var($this->option('if-stale'), FILTER_VALIDATE_INT);
        if ($minutes !== null && ($minutes === false || $minutes < 1)) {
            $this->error('--if-stale must be a positive number of minutes.');

            return self::FAILURE;
        }
        $runScope = $sport ? 'sport:'.$sport : 'all_sports';
        $sports = $sport ? [$sport] : SportCatalog::ALL;
        $locks = [];
        try {
            foreach ($sports as $target) {
                $lock = Cache::lock('healthcheck:validation:sport:'.$target.':'.$scope, 7200);
                if (! $lock->get()) {
                    $this->warn('Validation is already running for this sport and scope.');

                    return self::FAILURE;
                }
                $locks[] = $lock;
            }
            if ($minutes !== null && ($recent = $this->recorder->recent($runScope, $scope, $minutes))) {
                $this->info('Recent completed validation is available.');

                return (new HealthcheckOutcome($recent->status))->exitCode((bool) $this->option('report-only'));
            }
            $this->info('Running data validation checks...');

            return $this->runValidation($sports, $runScope, $scope);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /** @param list<string> $sports */
    private function runValidation(array $sports, string $runScope, string $scope): int
    {
        $run = $this->recorder->begin($runScope, $scope, (bool) $this->option('skip-alerts'));
        try {
            foreach ($sports as $sport) {
                $this->line("Validating {$sport} ({$scope})...");
                $resultCount = 0;
                foreach ($this->sportValidator->results($sport, $scope) as $result) {
                    $resultCount++;
                    $this->recordCheck($run, $sport, $result);
                }
                if ($resultCount === 0) {
                    $this->recordCheck($run, $sport, ['check_type' => 'validation_configuration', 'status' => 'failing',
                        'message' => 'No validation profile or checks configured for this sport.', 'metadata' => ['execution_failed' => true]]);
                }
            }
            $this->recorder->complete($run);
        } catch (Throwable $error) {
            report($error);
            $this->recorder->complete($run, $error);
            $this->error('Validation could not complete; partial findings have been preserved.');

            return self::FAILURE;
        }
        try {
            $this->persistAiSummary($run);
        } catch (Throwable $error) {
            report($error);
            $this->recorder->recordPostProcessingError($run, 'summary', $error);
            $this->warn('Validation findings were saved, but the summary could not complete.');
        }
        if (! $this->option('skip-alerts')) {
            try {
                $this->validationRegressionAlertService->maybeNotify($run);
            } catch (Throwable $error) {
                report($error);
                $this->recorder->recordPostProcessingError($run, 'alerts', $error);
                $this->warn('Validation findings were saved, but alerts could not complete.');
            }
        }

        return $this->displayResults($run);
    }

    private function validationScope(): ?string
    {
        $scope = (string) ($this->option('data-only') ? 'data' : $this->option('scope'));
        $scope = strtolower(trim($scope));

        if (! in_array($scope, SportValidator::supportedScopes(), true)) {
            $this->error('Invalid --scope value. Supported scopes: '.implode(', ', SportValidator::supportedScopes()).'.');

            return null;
        }

        return $scope;
    }

    protected function recordCheck(ValidationRun $run, string $sport, array $result): void
    {
        $this->recorder->record($run, $sport, $result);
        $this->line("  [{$result['check_type']}] {$result['status']}: {$result['message']}");
        if ($result['status'] !== 'passing') {
            $this->displayFindingDetails((array) ($result['metadata'] ?? []), $result['recommended_action'] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function displayFindingDetails(array $metadata, ?string $recommendedAction): void
    {
        $sampleGames = collect((array) data_get($metadata, 'sample_games', []))
            ->take(3)
            ->map(function (mixed $game): string {
                if (! is_array($game)) {
                    return (string) $game;
                }

                $label = data_get($game, 'matchup') ?: data_get($game, 'game_id');
                $eventId = data_get($game, 'espn_event_id');
                $reasons = collect((array) data_get($game, 'reasons', []))
                    ->filter()
                    ->implode(',');

                return trim(implode(' | ', array_filter([
                    'game_id='.data_get($game, 'game_id'),
                    $eventId ? 'espn='.$eventId : null,
                    $label ? 'matchup='.$label : null,
                    $reasons ? 'reasons='.$reasons : null,
                ])));
            })
            ->filter()
            ->values();

        if ($sampleGames->isNotEmpty()) {
            $this->line('    samples: '.$sampleGames->implode(' ; '));
        } elseif (data_get($metadata, 'sample_game_ids') !== null) {
            $sampleIds = collect((array) data_get($metadata, 'sample_game_ids'))
                ->take(5)
                ->implode(',');

            if ($sampleIds !== '') {
                $this->line('    sample_game_ids: '.$sampleIds);
            }
        }

        if ($recommendedAction) {
            $this->line('    recommended: '.$recommendedAction);
        }
    }

    protected function displayResults(ValidationRun $run): int
    {
        $this->newLine();
        $this->info('Validation Summary:');
        foreach (['passing', 'warning', 'failing'] as $status) {
            $count = (int) ($run->summary[$status] ?? 0);
            if ($count > 0) {
                $this->line("{$status}: {$count} checks");
            }
        }
        $executionFailed = data_get($run->summary, 'execution_status') === 'failed';
        $this->line('Validation execution: '.($executionFailed ? 'failed' : 'completed').'; findings: '.$run->status.'.');

        return (new HealthcheckOutcome($run->status, $executionFailed))->exitCode((bool) $this->option('report-only'));
    }

    protected function persistAiSummary(ValidationRun $run): void
    {
        $summary = $this->option('skip-ai-summary')
            ? $this->validationReviewSummaryService->buildFallbackSummary($run->findings()->get(), $run)
            : $this->validationReviewSummaryService->summarizeRun($run);
        $generatedBy = (string) ($summary['generated_by'] ?? '');
        $provider = null;
        $model = null;

        if ($generatedBy !== '') {
            $parts = explode(':', $generatedBy, 2);
            $provider = $parts[0] ?? null;
            $model = $parts[1] ?? $generatedBy;
        }

        $run->forceFill([
            'ai_summary' => $summary,
            'ai_provider' => $provider,
            'ai_model' => $model,
            'ai_generated_at' => now(),
        ])->save();
    }
}
