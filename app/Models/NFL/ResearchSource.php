<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;

class ResearchSource extends Model
{
    protected $table = 'nfl_research_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_at' => 'immutable_datetime', 'succeeded_at' => 'immutable_datetime', 'latest_published_at' => 'immutable_datetime'];
    }
}
