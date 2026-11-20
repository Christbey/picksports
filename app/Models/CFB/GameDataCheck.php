<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataCheck extends Model
{
    protected $table = 'cfb_game_data_checks';

    protected $guarded = ['id'];

    public function isCurrentAccepted(): bool
    {
        return $this->accepted_at !== null && self::where('game_id', $this->game_id)->where('component', $this->component)
            ->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->value('id') === $this->id;
    }

    protected function casts(): array
    {
        return ['discrepancies' => 'array', 'evidence' => 'array', 'accepted_at' => 'immutable_datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
