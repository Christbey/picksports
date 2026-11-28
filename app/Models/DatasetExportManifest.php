<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Models\Concerns\RejectsPersistedModelMutation;
use Database\Factories\DatasetExportManifestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatasetExportManifest extends Model
{
    /** @use HasFactory<DatasetExportManifestFactory> */
    use HasFactory, HasPublicUlid, RejectsPersistedModelMutation;

    protected $fillable = [
        'public_id',
        'dataset',
        'sport',
        'season',
        'format',
        'content_type',
        'disk',
        'object_key',
        'manifest_key',
        'uri',
        'sha256',
        'manifest_sha256',
        'schema_hash',
        'row_count',
        'size_bytes',
        'source_table',
        'source_max_id',
        'exported_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'row_count' => 'integer',
            'size_bytes' => 'integer',
            'source_max_id' => 'integer',
            'exported_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected function immutableMutationMessage(): string
    {
        return 'Dataset export manifests are immutable.';
    }

    protected static function newFactory(): DatasetExportManifestFactory
    {
        return DatasetExportManifestFactory::new();
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(CanonicalPrediction::class);
    }
}
