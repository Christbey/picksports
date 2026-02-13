<?php

namespace App\Jobs\ESPN\CBB;

use App\Actions\ESPN\CBB\SyncTeamSchedule;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchTeamSchedule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $teamEspnId
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncTeamSchedule($service);

        $count = $action->execute($this->teamEspnId);

        Log::info("CBB: Synced {$count} games from team schedule for team ESPN ID {$this->teamEspnId}");
    }
}
