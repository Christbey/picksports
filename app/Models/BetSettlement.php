<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetSettlement extends Model
{
    protected $fillable = [
        'bet_decision_id',
        'result_status',
        'result_value',
        'profit_units',
        'closing_price',
        'closing_line',
        'clv',
        'graded_at',
        'settled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'graded_at' => 'datetime',
            'settled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(BetDecision::class, 'bet_decision_id');
    }
}
