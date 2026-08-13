<?php

namespace App\Jobs\ESPN\MLB;

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchGamesFromScoreboard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $date
    ) {}

    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncGamesFromScoreboard($service);

        $count = $action->execute($this->date);

        Log::info("MLB: Synced {$count} games from ESPN scoreboard for date {$this->date}");
    }
}
