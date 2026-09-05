<?php

namespace App\Models;

use Database\Factories\ProviderSourceFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderSourceFile extends Model
{
    /** @use HasFactory<ProviderSourceFileFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'public_id',
        'provider',
        'dataset',
        'sha256',
        'disk',
        'object_key',
        'uri',
        'original_filename',
        'content_type',
        'compression',
        'size_bytes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ProviderImportManifest::class);
    }

    protected static function newFactory(): ProviderSourceFileFactory
    {
        return ProviderSourceFileFactory::new();
    }
}
