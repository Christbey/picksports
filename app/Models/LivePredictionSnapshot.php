<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LivePredictionSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['baseline' => 'array', 'state' => 'array', 'projection' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Live prediction snapshots are append-only.'));
        static::deleting(fn () => throw new LogicException('Live prediction snapshots are append-only.'));
    }

    public function canonicalPrediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class);
    }
}
