<?php

namespace App\Models;

use App\Support\Models\GuardsCoreEvidenceMutations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionReviewRevision extends Model
{
    use GuardsCoreEvidenceMutations;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input_payload' => 'array', 'execution_spec' => 'array', 'output' => 'array', 'decision' => 'array',
            'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Prediction review revisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Prediction review revisions are immutable.'));
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(AnalysisWorkItem::class, 'analysis_work_item_id');
    }
}
