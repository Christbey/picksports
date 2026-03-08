<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sport,
        public readonly int $gameId,
        public readonly ?int $season,
        public readonly string $gameModelClass,
    ) {}
}
