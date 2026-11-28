<?php

namespace App\Jobs\Predictions;

use App\Services\Predictions\PublicationEffectDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Durable database ownership replaces a cache-based ShouldBeUnique lock. */
class DeliverPublicationEffect implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $effectId, public string $ownerToken) {}

    public function handle(PublicationEffectDelivery $delivery): void
    {
        $delivery->process($this->effectId, $this->ownerToken);
    }
}
