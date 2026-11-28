<?php

namespace App\Models;

use App\Models\Concerns\ImmutableEloReceipt;
use Illuminate\Database\Eloquent\Model;

class SportEloApplicationEffect extends Model
{
    use ImmutableEloReceipt;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entity_id' => 'integer', 'rating_before' => 'decimal:4', 'rating_after' => 'decimal:4', 'rating_delta' => 'decimal:4', 'games_started_before' => 'integer', 'games_started_after' => 'integer', 'evidence' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
