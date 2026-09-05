<?php

namespace App\Models;

use App\Enums\PredictionSport;
use Database\Factories\UserBetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBet extends Model
{
    use HasFactory;

    protected static function newFactory(): UserBetFactory
    {
        return UserBetFactory::new();
    }

    protected $fillable = [
        'user_id',
        'prediction_id',
        'prediction_type',
        'prediction_sport',
        'sport_event_id',
        'bet_amount',
        'odds',
        'bet_type',
        'selection_side',
        'selection_label',
        'line',
        'result',
        'profit_loss',
        'notes',
        'placed_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'prediction_sport' => PredictionSport::class,
            'bet_amount' => 'decimal:2',
            'line' => 'decimal:2',
            'profit_loss' => 'decimal:2',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function normalizedPredictionSport(): ?PredictionSport
    {
        if ($this->prediction_sport instanceof PredictionSport) {
            return $this->prediction_sport;
        }

        return PredictionSport::tryFrom((string) $this->prediction_sport)
            ?? PredictionSport::fromLegacyModelClass($this->prediction_type);
    }
}
