<?php

namespace Database\Factories;

use App\Models\ProviderSourceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSourceFile>
 */
class ProviderSourceFileFactory extends Factory
{
    protected $model = ProviderSourceFile::class;

    public function definition(): array
    {
        $sha256 = hash('sha256', $this->faker->unique()->uuid());

        return [
            'provider' => 'nflverse',
            'dataset' => 'weekly-stats',
            'sha256' => $sha256,
            'disk' => 'provider-local',
            'object_key' => "providers/nflverse/weekly-stats/{$sha256}/weekly.csv.gz",
            'uri' => "storage://provider-local/providers/nflverse/weekly-stats/{$sha256}/weekly.csv.gz",
            'original_filename' => 'weekly.csv.gz',
            'content_type' => 'application/gzip',
            'compression' => 'gzip',
            'size_bytes' => $this->faker->numberBetween(100, 1000000),
            'metadata' => [],
        ];
    }
}
