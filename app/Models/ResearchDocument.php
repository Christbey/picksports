<?php

namespace App\Models;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchDocument extends Model
{
    use RejectsPersistedModelMutation;

    protected $guarded = ['id'];

    protected function immutableMutationMessage(): string
    {
        return 'Research document versions are immutable.';
    }

    protected function casts(): array
    {
        return ['claims' => 'array', 'published_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class, 'source_id');
    }
}
