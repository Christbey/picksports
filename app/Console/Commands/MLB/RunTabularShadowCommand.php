<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbChallengerShadowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RunTabularShadowCommand extends Command
{
    protected $signature = 'mlb:run-tabular-shadow
        {--artifact= : Restrict inference to one registered artifact UUID}
        {--game= : Restrict inference to one upcoming MLB game}
        {--limit=0 : Maximum canonical snapshots to process}
        {--minimum-edge=0.03 : Edge required by the tracking decision layer}
        {--skip-decisions : Record shadow outputs without creating tracking decisions}';

    protected $description = 'Run the registered MLB challenger against canonical pregame snapshots';

    public function handle(MlbChallengerShadowService $shadows): int
    {
        $result = $shadows->run(
            filled($this->option('artifact')) ? (string) $this->option('artifact') : null,
            filled($this->option('game')) ? (int) $this->option('game') : null,
            max(0, (int) $this->option('limit')),
        );

        $this->line($result['message']);
        $this->line("Snapshots considered: {$result['considered']}");
        $this->line("Snapshots inferred: {$result['inferred']}");
        $this->line("Shadow outputs created: {$result['outputs_created']}");

        foreach ($result['reasons'] as $reason => $count) {
            $this->line("{$reason}: {$count}");
        }

        if (! $this->option('skip-decisions') && $result['artifact_id'] !== null && $result['inferred'] > 0) {
            Artisan::call('sports:record-shadow-bet-decisions', [
                '--sport' => 'mlb',
                '--artifact' => $result['artifact_id'],
                '--minimum-edge' => (float) $this->option('minimum-edge'),
            ]);
            $this->line(trim(Artisan::output()));
        }

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
