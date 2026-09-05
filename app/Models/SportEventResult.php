<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SportEventResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SportEventResult extends Model
{
    /** @use HasFactory<SportEventResultFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'sport_event_id',
        'revision',
        'supersedes_sport_event_result_id',
        'status',
        'home_score',
        'away_score',
        'source',
        'source_reference',
        'result_hash',
        'observed_at',
        'finalized_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'observed_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Sport event results are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Sport event results are immutable.'));
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_sport_event_result_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_sport_event_result_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(PredictionEvaluation::class);
    }
}
