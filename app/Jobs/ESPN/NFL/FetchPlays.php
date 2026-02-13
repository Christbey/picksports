<?php

namespace App\Jobs\ESPN\NFL;

use App\Actions\ESPN\NFL\SyncPlays;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPlays implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $eventId
    ) {}

    public function handle(): void
    {
        $service = new EspnService;
        $action = new SyncPlays($service);

        $count = $action->execute($this->eventId);

        Log::info("NFL: Synced {$count} plays for event {$this->eventId} from ESPN");
    }
}
