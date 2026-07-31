<?php

namespace App\Jobs\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncGames;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CFB\CfbWeek;
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
        $espnWeek = CfbWeek::espnWeekForProductWeek($this->seasonType, $this->week);

        $count = $action->execute($this->season, $this->seasonType, $espnWeek);

        Log::info("CFB: Synced {$count} games from ESPN for Season {$this->season}, Week {$this->week}", [
            'espn_week' => $espnWeek,
        ]);
    }
}
