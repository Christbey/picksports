<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;

class ResearchDocument extends Model
{
    protected $table = 'nfl_research_documents';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['structured' => 'array', 'published_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }
}
