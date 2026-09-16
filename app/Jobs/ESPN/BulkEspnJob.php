<?php

namespace App\Jobs\ESPN;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BulkEspnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bulk ESPN imports are isolated from latency-sensitive score/detail work.
     */
    public function __construct()
    {
        $this->onQueue('sync');
    }

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 1800;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];
}
