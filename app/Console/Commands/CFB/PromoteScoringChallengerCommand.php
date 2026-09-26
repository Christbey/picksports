<?php

namespace App\Console\Commands\CFB;

use App\Models\ModelArtifact;
use App\Services\CFB\Scoring\CfbChallengerValidation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteScoringChallengerCommand extends Command
{
    protected $signature = 'cfb:promote-scoring-challenger {--artifact=} {--rollback}';

    protected $description = 'Apply measured forecast promotion gates, or return the artifact to shadow mode without rewriting history';

    public function handle(CfbChallengerValidation $validation): int
    {
        $artifact = ModelArtifact::whereKey($this->option('artifact'))->where('sport', 'cfb')->where('model_type', 'cfb_possession')->first();
        if (! $artifact) {
            return self::FAILURE;
        }
        if ($this->option('rollback')) {
            $artifact->update(['status' => 'challenger', 'promotion_decision' => ['forecast_allowed' => false, 'betting_allowed' => false, 'rolled_back_at' => now()->toIso8601String()]]);
            $this->info('Future forecasts use the released baseline. Existing revisions remain unchanged.');

            return self::SUCCESS;
        }
        $report = $validation->evaluate($artifact);
        if (! $report['forecast_allowed']) {
            $this->line(json_encode($report));

            return 2;
        }
        DB::transaction(fn () => ModelArtifact::whereKey($artifact->id)->update(['status' => 'promoted', 'promoted_at' => now(), 'promotion_decision' => $report]));
        $this->info('Forecast artifact promoted; market recommendations retain independent validation requirements.');

        return self::SUCCESS;
    }
}
