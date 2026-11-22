<?php

namespace App\Models\NBA;

use App\Models\ResearchSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchContextSnapshot extends Model
{
    protected $table = 'nba_research_context_snapshots';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responses' => 'array', 'captured_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('NBA context evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('NBA context evidence is immutable.'));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class);
    }
}
