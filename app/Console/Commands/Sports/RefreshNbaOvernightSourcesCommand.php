<?php

namespace App\Console\Commands\Sports;

use App\Services\CommandHeartbeatService;
use App\Services\NBA\Research\OvernightSourceBridge;
use Illuminate\Console\Command;
use Throwable;

class RefreshNbaOvernightSourcesCommand extends Command
{
    protected $signature = 'nba:refresh-overnight-sources {--dry-run : Inspect admission without acquisition, writes or dispatch}';

    protected $description = 'Bounded NBA overnight source acquisition and descriptive assessment; never invokes paid review';

    public function handle(OvernightSourceBridge $bridge, CommandHeartbeatService $health): int
    {
        try {
            $result = $bridge->run((bool) $this->option('dry-run'));
        } catch (Throwable $error) {
            $result = ['status' => 'failed', 'reason' => 'bridge_execution_failed', 'error_class' => class_basename($error)];
        }
        if (! $this->option('dry-run') && config('nba.source_bridge.enabled', false)) {
            // This is actual acquisition/assessment completion, distinct from the outer dispatcher heartbeat.
            if ($result['status'] === 'failed') {
                $health->recordFailure('nba:refresh-overnight-sources', 'nba', 'nba_source_bridge', $result['reason'], $result);
            } else {
                $health->recordSuccess('nba:refresh-overnight-sources', 'nba', 'nba_source_bridge', $result);
            }
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
