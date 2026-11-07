<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class FetchPlayerInjuries extends BulkEspnJob implements ShouldBeUnique
{
    public function __construct(
        public ?string $teamEspnId = null
    ) {
        parent::__construct();
    }

    public int $uniqueFor = 2100;

    public function uniqueId(): string
    {
        return $this->teamEspnId ?: 'all-teams';
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlayerInjuries($service);

        try {
            if ($this->teamEspnId) {
                $count = $action->execute($this->teamEspnId);
                Log::info("CFB: Synced {$count} player injuries for team {$this->teamEspnId} from ESPN");
            } else {
                $count = $action->syncAllTeams();
                Log::info("CFB: Synced {$count} total player injuries from ESPN");
            }
        } finally {
            $code = Artisan::call('cfb:sync-official-availability', ['--days-forward' => 8]);
            $summary = json_decode(trim(Artisan::output()), true);
            Log::info('CFB: Official availability ingest finished', [
                'reports' => $summary['reports'] ?? null, 'partial_reports' => $summary['partial_reports'] ?? null,
                'uncovered_teams' => $summary['uncovered_teams'] ?? null, 'source_failures' => $summary['source_failures'] ?? [],
            ]);
            if ($code !== 0) {
                Log::warning('CFB: Official availability sync incomplete; missing coverage remains unknown', ['exit_code' => $code]);
            }
        }
    }
}
