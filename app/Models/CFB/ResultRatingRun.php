<?php

namespace App\Models\CFB;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Model;

class ResultRatingRun extends Model
{
    use RejectsPersistedModelMutation;

    protected $table = 'cfb_result_rating_runs';

    public $timestamps = false;

    protected $guarded = [];

    protected function immutableMutationMessage(): string
    {
        return 'Frozen CFB candidate runs are immutable.';
    }

    protected function casts(): array
    {
        return ['source_rows' => 'array', 'model' => 'array', 'source_available_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
