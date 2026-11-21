<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameResearchAssessment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Research assessment revisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Research assessment revisions are immutable.'));
    }

    protected function casts(): array
    {
        return ['assessment' => 'array', 'assessed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
