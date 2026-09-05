<?php

namespace App\Models;

use Database\Factories\CalculationRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;
use LogicException;

class CalculationRun extends Model
{
    /** @use HasFactory<CalculationRunFactory> */
    use HasFactory, HasUuids;

    public const STATUSES = ['pending', 'running', 'succeeded', 'failed'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'sport_event_id',
        'event_input_snapshot_id',
        'calculation_release_id',
        'phase',
        'trigger',
        'idempotency_key',
        'status',
        'started_at',
        'completed_at',
        'output_hash',
        'diagnostics',
        'failure_code',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'diagnostics' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            $identityFields = [
                'sport_event_id',
                'event_input_snapshot_id',
                'calculation_release_id',
                'phase',
                'trigger',
                'idempotency_key',
            ];

            if (array_intersect($identityFields, array_keys($run->getDirty())) !== []) {
                throw new LogicException('Calculation run identity is immutable.');
            }

            if ($run->getOriginal('status') === 'succeeded') {
                throw new LogicException('Successful calculation runs are immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Calculation runs cannot be deleted.'));
    }

    public function setStatusAttribute(string $status): void
    {
        $status = strtolower(trim($status));

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported calculation run status.');
        }

        $this->attributes['status'] = $status;
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function inputSnapshot(): BelongsTo
    {
        return $this->belongsTo(EventInputSnapshot::class, 'event_input_snapshot_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CalculationRelease::class, 'calculation_release_id');
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(CanonicalPrediction::class);
    }
}
