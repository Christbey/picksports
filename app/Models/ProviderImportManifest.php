<?php

namespace App\Models;

use Database\Factories\ProviderImportManifestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderImportManifest extends Model
{
    /** @use HasFactory<ProviderImportManifestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'public_id',
        'provider_source_file_id',
        'provider',
        'dataset',
        'status',
        'options',
        'rows_read',
        'rows_imported',
        'rows_skipped',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'rows_read' => 'integer',
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(ProviderSourceFile::class, 'provider_source_file_id');
    }

    protected static function newFactory(): ProviderImportManifestFactory
    {
        return ProviderImportManifestFactory::new();
    }
}
