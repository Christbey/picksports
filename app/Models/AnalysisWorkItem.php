<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalysisWorkItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input_payload' => 'array', 'execution_spec' => 'array', 'evidence' => 'array',
            'attempts' => 'integer', 'deadline_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'last_dispatched_at' => 'immutable_datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PredictionReviewRevision::class);
    }
}
