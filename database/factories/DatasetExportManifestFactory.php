<?php

namespace Database\Factories;

use App\Models\DatasetExportManifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DatasetExportManifest> */
class DatasetExportManifestFactory extends Factory
{
    protected $model = DatasetExportManifest::class;

    public function definition(): array
    {
        $sha256 = fake()->sha256();

        return [
            'dataset' => 'historical-plays',
            'sport' => 'mlb',
            'season' => 2025,
            'format' => 'jsonl',
            'content_type' => 'application/x-ndjson',
            'disk' => 'ml-local',
            'object_key' => "ml/datasets/historical-plays/sport=mlb/season=2025/{$sha256}/part-00000.jsonl",
            'manifest_key' => "ml/datasets/historical-plays/sport=mlb/season=2025/{$sha256}/manifest.json",
            'uri' => "storage://ml-local/ml/datasets/historical-plays/sport=mlb/season=2025/{$sha256}/part-00000.jsonl",
            'sha256' => $sha256,
            'manifest_sha256' => fake()->sha256(),
            'schema_hash' => fake()->sha256(),
            'row_count' => 100,
            'size_bytes' => 10000,
            'source_table' => 'mlb_plays',
            'source_max_id' => 100,
            'exported_at' => now(),
            'metadata' => [],
        ];
    }
}
