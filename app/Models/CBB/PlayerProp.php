<?php

namespace App\Models\CBB;

use App\Models\Sports\AbstractPlayerProp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProp extends AbstractPlayerProp
{
    protected $table = 'cbb_player_props';

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
