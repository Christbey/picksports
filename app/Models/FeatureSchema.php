<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class FeatureSchema extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'public_id',
        'sport',
        'version',
        'schema_hash',
        'definition',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Feature schemas are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Feature schemas are immutable.'));
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(CanonicalPrediction::class);
    }
}
