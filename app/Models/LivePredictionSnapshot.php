<?php

namespace App\Models;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivePredictionSnapshot extends Model
{
    use HasFactory, RejectsPersistedModelMutation;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['baseline' => 'array', 'state' => 'array', 'projection' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    protected function immutableMutationMessage(): string
    {
        return 'Live prediction snapshots are append-only.';
    }

    public function canonicalPrediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class);
    }
}
