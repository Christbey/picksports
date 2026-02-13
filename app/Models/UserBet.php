<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserBet extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'prediction_id',
        'prediction_type',
        'bet_amount',
        'odds',
        'bet_type',
        'result',
        'profit_loss',
        'notes',
        'placed_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'bet_amount' => 'decimal:2',
            'profit_loss' => 'decimal:2',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prediction(): MorphTo
    {
        return $this->morphTo();
    }
}
