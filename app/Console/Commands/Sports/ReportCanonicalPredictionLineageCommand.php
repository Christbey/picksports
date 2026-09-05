<?php

namespace App\Console\Commands\Sports;

use App\Services\Predictions\CanonicalPredictionLineageReadinessService;
use App\Support\SportCatalog;
use Illuminate\Console\Command;

class ReportCanonicalPredictionLineageCommand extends Command
{
    protected $signature = 'sports:report-canonical-prediction-lineage
        {--sport=* : Sport keys to inspect (defaults to all)}
        {--limit=0 : Maximum canonical predictions to inspect}
        {--json : Emit the report as JSON}
        {--fail-on-incomplete : Return a failure status when any prediction is incomplete or inconsistent}';

    protected $description = 'Compare canonical prediction provenance links and report lineage readiness';

    public function handle(CanonicalPredictionLineageReadinessService $readiness): int
    {
        $sports = collect((array) $this->option('sport'))
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values();
        $unsupported = $sports->diff(SportCatalog::ALL);
        if ($unsupported->isNotEmpty()) {
            $this->error('Unsupported sport(s): '.$unsupported->implode(', ').'.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0) {
            $this->error('The --limit option must be a non-negative integer.');

            return self::FAILURE;
        }

        $report = $readiness->report($sports->all(), $limit);
        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Metric', 'Count'], [
                ['Predictions inspected', $report['total']],
                ['Lineage ready', $report['ready']],
                ['Incomplete or inconsistent', $report['incomplete']],
                ['Ready percentage', number_format($report['ready_percentage'], 2).'%'],
            ]);
            $this->table(
                ['Link', 'Missing', 'Mismatched'],
                collect(['sport_event', 'feature_schema', 'dataset_export_manifest', 'model_run', 'model_artifact'])
                    ->map(fn (string $link): array => [
                        $link,
                        $report['missing'][$link],
                        $report['mismatches'][$link],
                    ])
                    ->push(['artifact_dataset', 0, $report['mismatches']['artifact_dataset']])
                    ->all(),
            );

            if ($report['samples'] !== []) {
                $this->warn('Sample incomplete lineage records (links are intentionally not inferred when ambiguous):');
                $this->table(
                    ['Prediction', 'Sport', 'Detail ID', 'Missing', 'Mismatches'],
                    collect($report['samples'])->map(fn (array $sample): array => [
                        $sample['public_id'],
                        $sample['sport'],
                        $sample['detail_id'],
                        implode(', ', $sample['missing']),
                        implode(', ', $sample['mismatches']),
                    ])->all(),
                );
            }
        }

        return (bool) $this->option('fail-on-incomplete') && $report['incomplete'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
