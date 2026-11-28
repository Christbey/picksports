<?php

namespace App\Models;

use App\Models\Concerns\ImmutableEloReceipt;
use Illuminate\Database\Eloquent\Model;

class SportEloSeasonInitialization extends Model
{
    use ImmutableEloReceipt;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_history_id' => 'integer', 'prior_effect_id' => 'integer', 'entity_id' => 'integer', 'season' => 'integer', 'rating_before' => 'decimal:4', 'rating_after' => 'decimal:4', 'prior_rating' => 'decimal:4', 'regression_factor' => 'decimal:7', 'evidence' => 'array', 'observed_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
