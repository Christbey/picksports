<?php

namespace App\Jobs\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncGamesFromScoreboard;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchGamesFromScoreboard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $date
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncGamesFromScoreboard($service);

        $count = $action->execute($this->date);

        Log::info("WCBB: Synced {$count} games from ESPN scoreboard for date {$this->date}");
    }
}
