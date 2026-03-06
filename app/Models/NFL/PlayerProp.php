<?php

namespace App\Models\NFL;

use App\Models\Sports\AbstractPlayerProp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProp extends AbstractPlayerProp
{
    protected $table = 'nfl_player_props';

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
