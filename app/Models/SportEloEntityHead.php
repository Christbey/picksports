<?php

namespace App\Models;

use App\Casts\UtcEloDateTime;
use Illuminate\Database\Eloquent\Model;

class SportEloEntityHead extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['initialization_id' => 'integer', 'last_effect_id' => 'integer', 'last_game_id' => 'integer', 'entity_id' => 'integer', 'season' => 'integer', 'generation' => 'integer', 'rating' => 'decimal:4', 'last_played_at' => UtcEloDateTime::class];
    }
}
