<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncGames;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchGames implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $season,
        public int $seasonType,
        public int $week
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncGames($service);

        $count = $action->execute($this->season, $this->seasonType, $this->week);

        Log::info("WCBB: Synced {$count} games from ESPN for Season {$this->season}, Week {$this->week}");
    }
}
