<?php

namespace App\Console\Commands\CFB;

use App\Models\ModelArtifact;
use App\Services\CFB\Scoring\CfbChallengerValidation;
use Illuminate\Console\Command;

class EvaluateScoringChallengerCommand extends Command
{
    protected $signature = 'cfb:evaluate-scoring-challenger {--artifact=} {--report=}';

    protected $description = 'Evaluate immutable CFB challenger holdouts and independent prospective game evidence';

    public function handle(CfbChallengerValidation $validation): int
    {
        try {
            $artifact = ModelArtifact::whereKey($this->option('artifact'))->where('sport', 'cfb')->where('model_type', 'cfb_possession')->firstOrFail();
            $report = $validation->evaluate($artifact);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if ($this->option('report')) {
                file_put_contents($this->option('report'), $json);
            }
            $this->line($json);

            return $report['forecast_allowed'] ? self::SUCCESS : 2;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
