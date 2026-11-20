<?php

namespace App\Models;

use App\Enums\PredictionSport;
use App\Models\Relations\AllowedPredictionMorphTo;
use Database\Factories\UserBetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public function prediction(): MorphTo
    {
        $class = self::supportedPredictionModel($this->getAttribute('prediction_type'));
        $query = $class === null
            ? $this->newQuery()->setEagerLoads([])
            : (new $class)->newQuery();
        $hasReference = array_key_exists('prediction_type', $this->getAttributes()) || $this->prediction_id !== null;
        if ($hasReference) {
            $sport = $this->normalizedPredictionSport();
            if ($class === null || $sport === null || ($class !== CanonicalPrediction::class && PredictionSport::fromLegacyModelClass($class) !== $sport)) {
                $query->whereRaw('1 = 0');
            } elseif ($class === CanonicalPrediction::class) {
                $query->where('sport', $sport->value)->whereHas('sportEvent', fn ($event) => $event->where('sport', $sport->value))
                    ->when($this->sport_event_id !== null, fn ($forecast) => $forecast->where('sport_event_id', $this->sport_event_id));
            } else {
                $query->where(fn ($forecast) => $forecast->whereDoesntHave('game.sportEvent')
                    ->orWhereHas('game.sportEvent', fn ($event) => $event->where('sport', $sport->value)))
                    ->when($this->sport_event_id !== null, fn ($forecast) => $forecast->whereHas('game', fn ($game) => $game->where('sport_event_id', $this->sport_event_id)));
            }
        }

        return new AllowedPredictionMorphTo($query, $this, 'prediction_id', 'id', 'prediction_type', 'prediction');
    }

    /** @return class-string<Model>|null */
    public static function supportedPredictionModel(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $class = static::getActualClassNameForMorph($type);

        return $class === CanonicalPrediction::class || PredictionSport::fromLegacyModelClass($class) !== null
            ? $class : null;
    }

    public function normalizedPredictionSport(): ?PredictionSport
    {
        $stored = $this->getAttributes()['prediction_sport'] ?? null;
        if ($stored !== null && $stored !== '') {
            return PredictionSport::tryFrom((string) $stored);
        }

        return PredictionSport::fromLegacyModelClass(self::supportedPredictionModel($this->prediction_type));
    }
}
