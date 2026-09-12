<?php

namespace App\Models\CFB;

use App\Models\Sports\AbstractPlayerProp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProp extends AbstractPlayerProp
{
    protected $table = 'cfb_player_props';

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
