<?php

namespace App\Models\NBA;

use App\Models\Concerns\RejectsPersistedModelMutation;
use App\Models\PredictionReviewRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only provenance association; the original revision owns paid time and expiry. */
class ReviewRevalidation extends Model
{
    use RejectsPersistedModelMutation;

    protected $table = 'nba_review_revalidations';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PredictionReviewRevision::class, 'prediction_review_revision_id');
    }

    protected function immutableMutationMessage(): string
    {
        return 'NBA review revalidations are immutable.';
    }
}
