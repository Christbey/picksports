<?php

namespace App\Models;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Model;

class GameResearchAssessment extends Model
{
    use RejectsPersistedModelMutation;

    protected $guarded = ['id'];

    protected function immutableMutationMessage(): string
    {
        return 'Research assessment revisions are immutable.';
    }

    protected function casts(): array
    {
        return ['assessment' => 'array', 'assessed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
