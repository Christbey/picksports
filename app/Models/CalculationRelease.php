<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Support\SportCatalog;
use Database\Factories\CalculationReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

class CalculationRelease extends Model
{
    /** @use HasFactory<CalculationReleaseFactory> */
    use HasFactory, HasPublicUlid;

    public const PHASES = ['pregame', 'live'];

    public const TYPES = ['rules', 'ml', 'hybrid'];

    public const STATUSES = ['draft', 'approved', 'retired', 'invalidated'];

    protected $fillable = [
        'public_id',
        'sport',
        'phase',
        'calculator_name',
        'release_type',
        'semantic_version',
        'code_revision',
        'configuration_hash',
        'input_schema_version',
        'configuration',
        'status',
        'effective_at',
        'approved_at',
        'retired_at',
        'invalidated_at',
        'approved_by',
        'approval_reason',
        'invalidation_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'metadata' => 'array',
            'effective_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $release): void {
            $originalStatus = $release->getOriginal('status');

            if (! in_array($originalStatus, ['approved', 'retired', 'invalidated'], true)) {
                return;
            }

            $allowed = ['status', 'retired_at', 'invalidated_at', 'invalidation_reason', 'updated_at'];
            $definitionChanges = array_diff(array_keys($release->getDirty()), $allowed);

            if ($definitionChanges !== []) {
                throw new LogicException('Approved calculation release definitions are immutable.');
            }

            if ($originalStatus === 'invalidated'
                && array_diff(array_keys($release->getDirty()), ['updated_at']) !== []) {
                throw new LogicException('Invalidated calculation releases are immutable.');
            }

            $newStatus = $release->status;
            $allowedTransitions = [
                'approved' => ['approved', 'retired', 'invalidated'],
                'retired' => ['retired', 'invalidated'],
                'invalidated' => ['invalidated'],
            ];

            if (! in_array($newStatus, $allowedTransitions[$originalStatus], true)) {
                throw new LogicException("Invalid calculation release transition from {$originalStatus} to {$newStatus}.");
            }

            if ($release->isDirty('retired_at') && ! in_array($newStatus, ['retired', 'invalidated'], true)) {
                throw new LogicException('A calculation release must be retired when retired_at is set.');
            }
        });

        static::deleting(function (self $release): void {
            if ($release->status !== 'draft') {
                throw new LogicException('Approved calculation releases cannot be deleted.');
            }
        });
    }

    public function setSportAttribute(string $sport): void
    {
        $sport = strtolower(trim($sport));

        if (! in_array($sport, SportCatalog::ALL, true)) {
            throw new InvalidArgumentException('Unsupported calculation release sport.');
        }

        $this->attributes['sport'] = $sport;
    }

    public function setPhaseAttribute(string $phase): void
    {
        $this->attributes['phase'] = $this->validatedValue($phase, self::PHASES, 'phase');
    }

    public function setReleaseTypeAttribute(string $type): void
    {
        $this->attributes['release_type'] = $this->validatedValue($type, self::TYPES, 'type');
    }

    public function setStatusAttribute(string $status): void
    {
        $this->attributes['status'] = $this->validatedValue($status, self::STATUSES, 'status');
    }

    public function components(): HasMany
    {
        return $this->hasMany(CalculationReleaseComponent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CalculationRun::class);
    }

    /** @param list<string> $allowed */
    private function validatedValue(string $value, array $allowed, string $field): string
    {
        $value = strtolower(trim($value));

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported calculation release {$field}.");
        }

        return $value;
    }
}
