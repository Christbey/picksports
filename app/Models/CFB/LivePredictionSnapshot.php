<?php

namespace App\Models\CFB;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivePredictionSnapshot extends Model
{
    use RejectsPersistedModelMutation;

    protected $table = 'cfb_live_prediction_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pregame' => 'array', 'state' => 'array', 'projection' => 'array', 'markets' => 'array', 'props' => 'array', 'observed_at' => 'datetime'];
    }

    protected function immutableMutationMessage(): string
    {
        return 'Live prediction snapshots are append-only.';
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
