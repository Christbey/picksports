<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;

class ResearchRevision extends Model
{
    protected $table = 'nfl_research_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['baseline' => 'array', 'revised' => 'array', 'evidence' => 'array', 'brief' => 'array', 'market' => 'array', 'evaluation' => 'array', 'created_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime'];
    }
}
