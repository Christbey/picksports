<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDocument extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Research document versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Research document versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['claims' => 'array', 'published_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class, 'source_id');
    }
}
