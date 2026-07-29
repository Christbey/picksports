<?php

namespace App\Console\Commands\Sports;

use App\Models\ModelArtifact;
use Illuminate\Console\Command;

class ReportModelLineageCommand extends Command
{
    protected $signature = 'sports:report-model-lineage {artifact : Model artifact UUID}';

    protected $description = 'Report model run, configuration, artifact, dataset, evaluation, and promotion lineage';

    public function handle(): int
    {
        $artifact = ModelArtifact::query()->with('trainingRun')->findOrFail((string) $this->argument('artifact'));

        $this->info('Model Lineage');
        $this->table(
            ['Field', 'Value'],
            [
                ['Sport / market', "{$artifact->sport} / {$artifact->market_type}"],
                ['Artifact id', $artifact->id],
                ['Artifact hash', $artifact->artifact_hash],
                ['Artifact path', $artifact->artifact_path],
                ['Artifact disk', $artifact->artifact_disk ?? 'legacy local'],
                ['Artifact object', $artifact->artifact_object_key ?? 'not registered'],
                ['Artifact URI', $artifact->artifact_uri ?? 'not registered'],
                ['Training run', $artifact->training_run_id],
                ['Config hash', $artifact->trainingRun?->config_hash ?? 'missing'],
                ['Code version', $artifact->trainingRun?->code_version ?? 'missing'],
                ['Dataset hash', $artifact->dataset_hash],
                ['Dataset URI', $artifact->dataset_uri ?? 'not registered'],
                ['Evaluation report hash', $artifact->evaluation_report_hash ?? 'not evaluated'],
                ['Evaluation report URI', $artifact->evaluation_report_uri ?? 'not registered'],
                ['Promotion status', $artifact->status],
            ],
        );

        return self::SUCCESS;
    }
}
