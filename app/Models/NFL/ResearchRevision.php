<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchRevision extends Model
{
    protected $table = 'nfl_research_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['baseline' => 'array', 'revised' => 'array', 'evidence' => 'array', 'brief' => 'array', 'market' => 'array', 'evaluation' => 'array', 'created_at' => 'immutable_datetime', 'grade_attempted_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
