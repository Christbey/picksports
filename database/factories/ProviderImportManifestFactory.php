<?php

namespace Database\Factories;

use App\Models\ProviderImportManifest;
use App\Models\ProviderSourceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderImportManifest>
 */
class ProviderImportManifestFactory extends Factory
{
    protected $model = ProviderImportManifest::class;

    public function definition(): array
    {
        return [
            'provider_source_file_id' => ProviderSourceFile::factory(),
            'provider' => 'nflverse',
            'dataset' => 'weekly-stats',
            'status' => 'completed',
            'options' => [],
            'rows_read' => 100,
            'rows_imported' => 95,
            'rows_skipped' => 5,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }
}
