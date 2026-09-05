<?php

namespace App\Models;

use Database\Factories\PredictionMarketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

class PredictionMarket extends Model
{
    /** @use HasFactory<PredictionMarketFactory> */
    use HasFactory, HasUlids;

    public const MARKET_TYPES = ['moneyline', 'spread', 'total'];

    public const SELECTIONS = ['home', 'away', 'combined'];

    protected $fillable = [
        'public_id',
        'prediction_id',
        'market_type',
        'selection',
        'projected_line',
        'probability',
        'confidence_score',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'projected_line' => 'decimal:4',
            'probability' => 'decimal:6',
            'confidence_score' => 'decimal:4',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $market): void {
            if ($market->prediction()
                ->whereNotNull('calculation_run_id')
                ->whereIn('publication_state', ['published', 'superseded', 'withdrawn'])
                ->exists()) {
                throw new LogicException('Markets on released canonical predictions are immutable.');
            }
        });

        static::deleting(function (self $market): void {
            if ($market->prediction()->whereNotNull('calculation_run_id')->exists()) {
                throw new LogicException('Markets on canonical prediction revisions cannot be deleted.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function setMarketTypeAttribute(string $marketType): void
    {
        if (! in_array($marketType, self::MARKET_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported canonical prediction market type.');
        }

        $this->attributes['market_type'] = $marketType;
    }

    public function setSelectionAttribute(string $selection): void
    {
        if (! in_array($selection, self::SELECTIONS, true)) {
            throw new InvalidArgumentException('Unsupported canonical prediction market selection.');
        }

        $this->attributes['selection'] = $selection;
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(CanonicalPrediction::class, 'prediction_id');
    }
}
