<?php

namespace App\Models;

use App\Casts\UtcEloDateTime;
use App\Models\Concerns\ImmutableEloReceipt;
use Illuminate\Database\Eloquent\Model;

class SportEloApplication extends Model
{
    use ImmutableEloReceipt;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['game_id' => 'integer', 'season' => 'integer', 'source_snapshot' => 'array', 'played_at' => UtcEloDateTime::class, 'recorded_at' => 'immutable_datetime'];
    }
}
