<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncTeamSchedule;
use App\Jobs\ESPN\BulkEspnJob;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Support\Facades\Log;

class FetchTeamSchedule extends BulkEspnJob
{
    public function __construct(
        public string $teamEspnId
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeamSchedule($service);

        $count = $action->execute($this->teamEspnId);

        Log::info("CBB: Synced {$count} games from team schedule for team ESPN ID {$this->teamEspnId}");
    }
}
