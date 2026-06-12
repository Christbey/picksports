<?php

namespace App\Console\Commands;

use App\Actions\Validation\SportValidator;
use App\Models\Healthcheck;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\Validation\ValidationRegressionAlertService;
use App\Services\Validation\ValidationReviewSummaryService;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthcheckValidateData extends Command
{
    protected $signature = 'healthcheck:validate-data
        {--sport= : Specific sport to validate (mlb, nba, nfl, cbb, cfb, wcbb, wnba)}
        {--scope=full : Validation scope to run: full or data}
        {--data-only : Shortcut for --scope=data; validates source data freshness/completeness without derived prediction or AI readiness checks}';

    protected $description = 'Run deep data validation checks across sports';

    public function __construct(
        private readonly SportValidator $sportValidator,
        private readonly ValidationReviewSummaryService $validationReviewSummaryService,
        private readonly ValidationRegressionAlertService $validationRegressionAlertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Running data validation checks...');

        $sports = $this->option('sport') ? [$this->option('sport')] : SportCatalog::ALL;
        $scope = $this->validationScope();

        if ($scope === null) {
            return Command::FAILURE;
        }

        $run = ValidationRun::query()->create([
            'command_name' => 'healthcheck:validate-data',
            'scope' => $this->option('sport') ? 'sport:'.$this->option('sport') : 'all_sports',
            'status' => 'running',
            'started_at' => now(),
        ]);

        foreach ($sports as $sport) {
            $this->line("Validating {$sport} ({$scope})...");

            $results = $this->sportValidator->validate($sport, $scope);

            if ($results === []) {
                $this->recordCheck(
                    $run,
                    $sport,
                    'validation_configuration',
                    'warning',
                    'No validation profile or checks configured for this sport.',
                    [],
                    'warning',
                    null
                );

                continue;
            }

            foreach ($results as $result) {
                $this->recordCheck(
                    $run,
                    $sport,
                    (string) $result['check_type'],
                    (string) $result['status'],
                    (string) $result['message'],
                    (array) ($result['metadata'] ?? []),
                    isset($result['severity']) ? (string) $result['severity'] : (string) $result['status'],
                    isset($result['recommended_action']) ? (string) $result['recommended_action'] : null
                );
            }
        }

        $run->forceFill([
            'status' => $this->resolveRunStatus($run),
            'summary' => $this->buildRunSummary($run),
            'completed_at' => now(),
        ])->save();

        $this->persistAiSummary($run);
        $this->validationRegressionAlertService->maybeNotify($run);

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

    protected function recordCheck(
        ValidationRun $run,
        string $sport,
        string $checkType,
        string $status,
        string $message,
        array $metadata = [],
        ?string $severity = null,
        ?string $recommendedAction = null
    ): void {
        Healthcheck::create([
            'sport' => $sport,
            'check_type' => $checkType,
            'status' => $status,
            'message' => $message,
            'metadata' => array_merge($metadata, [
                'validation_run_id' => $run->id,
                'recommended_action' => $recommendedAction,
            ]),
            'checked_at' => now(),
        ]);

        ValidationFinding::query()->create([
            'validation_run_id' => $run->id,
            'sport' => $sport,
            'check_type' => $checkType,
            'status' => $status,
            'severity' => $severity ?? $status,
            'message' => $message,
            'facts' => $metadata,
            'recommended_action' => $recommendedAction,
            'detected_at' => now(),
        ]);

        $color = match ($status) {
            'passing' => 'green',
            'warning' => 'yellow',
            'failing' => 'red',
            default => 'white',
        };

        $this->line("  [{$checkType}] <fg={$color}>{$status}</>: {$message}");

        if ($status !== 'passing') {
            $this->displayFindingDetails($metadata, $recommendedAction);
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

        $results = $run->findings()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        foreach ($results as $result) {
            $color = match ($result->status) {
                'passing' => 'green',
                'warning' => 'yellow',
                'failing' => 'red',
                default => 'white',
            };

            $this->line("<fg={$color}>{$result->status}: {$result->count} checks</>");
        }

        $failing = $run->findings()->where('status', 'failing')->count();

        return $failing > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function resolveRunStatus(ValidationRun $run): string
    {
        if ($run->findings()->where('status', 'failing')->exists()) {
            return 'failing';
        }

        if ($run->findings()->where('status', 'warning')->exists()) {
            return 'warning';
        }

        return 'passing';
    }

    /**
     * @return array<string, int>
     */
    protected function buildRunSummary(ValidationRun $run): array
    {
        $counts = $run->findings()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total_findings' => (int) $run->findings()->count(),
            'passing' => (int) ($counts['passing'] ?? 0),
            'warning' => (int) ($counts['warning'] ?? 0),
            'failing' => (int) ($counts['failing'] ?? 0),
        ];
    }

    protected function persistAiSummary(ValidationRun $run): void
    {
        $summary = $this->validationReviewSummaryService->summarizeRun($run);
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
