<?php

namespace App\Models;

use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlbGameReconciliationRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'evidence' => 'array'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
