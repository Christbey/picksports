<?php

namespace App\Models\NBA;

use App\Models\Concerns\RejectsPersistedModelMutation;
use App\Models\ResearchSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchContextSnapshot extends Model
{
    use RejectsPersistedModelMutation;

    protected $table = 'nba_research_context_snapshots';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['responses' => 'array', 'captured_at' => 'immutable_datetime'];
    }

    protected function immutableMutationMessage(): string
    {
        return 'NBA context evidence is immutable.';
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class);
    }
}
