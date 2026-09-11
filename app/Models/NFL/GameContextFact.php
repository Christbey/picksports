<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GameContextFact extends Model
{
    protected $table = 'nfl_game_context_facts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'published_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    // Retrospective evidence must not masquerade as information our model knew then.
    public function scopeKnownAt(Builder $query, \DateTimeInterface $cutoff): Builder
    {
        return $query->where('recorded_at', '<=', $cutoff)
            ->whereNotNull('published_at')->where('published_at', '<=', $cutoff);
    }
}
