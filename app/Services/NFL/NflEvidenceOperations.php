<?php

namespace App\Services\NFL;

use App\Models\ModelArtifact;
use Illuminate\Support\Facades\Cache;

class NflEvidenceOperations
{
    public function report(int $season, string $version): array
    {
        $build = function () use ($season, $version) {
            $audit = app(NflEvidenceLedgerAudit::class)->report($season, $version);
            $trials = [];
            foreach (ModelArtifact::with('trainingRun')->where('model_type', NflEvidenceTrialService::TYPE)->latest()->limit(10)->get() as $artifact) {
                try {
                    $trials[] = app(NflEvidenceTrialService::class)->evaluate($artifact);
                } catch (\Throwable $e) {
                    $trials[] = ['artifact_id' => $artifact->id, 'status' => 'integrity_failure', 'promoted' => false];
                }
            }

            return ['audit' => $audit, 'trials' => $trials, 'phase_status' => [
                'reconciliation' => 'implemented', 'prospective_capture' => 'implemented',
                'control_comparisons' => 'implemented_descriptive', 'later_game_validation' => 'preregistered_trials_require_future_results',
                'controlled_use' => 'shadow_only_no_production_promotion']];
        };

        return app()->environment('testing') ? $build() : Cache::remember('nfl:evidence:ops:'.$season.':'.$version, 60, $build);
    }
}
