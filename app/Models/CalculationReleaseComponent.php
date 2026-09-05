<?php

namespace App\Models;

use Database\Factories\CalculationReleaseComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CalculationReleaseComponent extends Model
{
    /** @use HasFactory<CalculationReleaseComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'calculation_release_id',
        'model_artifact_id',
        'component_type',
        'role',
        'market_type',
        'weight',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:6',
            'configuration' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $component): void {
            if ($component->release()->where('status', '!=', 'draft')->exists()) {
                throw new LogicException('Components of an approved calculation release are immutable.');
            }
        });

        static::deleting(function (self $component): void {
            if ($component->release()->where('status', '!=', 'draft')->exists()) {
                throw new LogicException('Components of an approved calculation release are immutable.');
            }
        });
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CalculationRelease::class, 'calculation_release_id');
    }

    public function modelArtifact(): BelongsTo
    {
        return $this->belongsTo(ModelArtifact::class);
    }
}
