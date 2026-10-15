<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;

class ResultRatingRun extends Model
{
    protected $table = 'cfb_result_rating_runs';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Frozen CFB candidate runs are immutable.'));
        static::deleting(fn () => throw new \LogicException('Frozen CFB candidate runs are immutable.'));
    }

    protected function casts(): array
    {
        return ['source_rows' => 'array', 'model' => 'array', 'source_available_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
