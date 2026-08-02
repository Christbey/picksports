<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbPeriodShadowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RunPeriodShadowCommand extends Command
{
    protected $signature = 'mlb:run-period-shadow
        {--artifact= : Restrict inference to one registered period artifact UUID}
        {--game= : Restrict inference to one upcoming MLB game}
        {--limit=0 : Maximum canonical snapshots to process}
        {--skip-decisions : Record outputs without creating tracking decisions}';

    protected $description = 'Run MLB F3/F5 challengers against canonical pregame snapshots';

    public function handle(MlbPeriodShadowService $shadows): int
    {
        $result = $shadows->run(
            filled($this->option('artifact')) ? (string) $this->option('artifact') : null,
            filled($this->option('game')) ? (int) $this->option('game') : null,
            max(0, (int) $this->option('limit')),
        );

        $this->line($result['message']);
        $this->line("Snapshots considered: {$result['considered']}");
        $this->line("Snapshots inferred: {$result['inferred']}");
        $this->line("Period outputs created: {$result['outputs_created']}");
        foreach ($result['reasons'] as $reason => $count) {
            $this->line("{$reason}: {$count}");
        }

        if (! $this->option('skip-decisions')
            && $result['artifact_id'] !== null
            && $result['inferred'] > 0) {
            Artisan::call('sports:record-shadow-bet-decisions', [
                '--sport' => 'mlb',
                '--artifact' => $result['artifact_id'],
                '--minimum-edge' => (float) config('mlb_ml.period_models.minimum_edge', 0.03),
            ]);
            $this->line(trim(Artisan::output()));
        }

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
