<?php

namespace App\Services\MachineLearning;

use App\Models\DatasetExportManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MlbArchivedPlayCoverage
{
    /** @return array<int, true> */
    public function forGames(Collection $games): array
    {
        if ($games->isEmpty()) {
            return [];
        }

        $bySeason = $games->groupBy('season');
        $covered = [];
        $exports = DatasetExportManifest::query()
            ->where('dataset', 'historical-plays')->where('sport', 'mlb')
            ->where('format', 'parquet')->where('source_table', 'mlb_plays')
            ->whereIn('season', $bySeason->keys())->lazyById(10);

        foreach ($exports as $export) {
            $counts = $export->metadata['verified_game_play_counts'] ?? null;
            if (! is_array($counts) || array_sum($counts) !== $export->row_count) {
                continue;
            }

            $matches = $bySeason->get($export->season)->filter(
                fn ($game): bool => is_int($counts[$game->getKey()] ?? null) && $counts[$game->getKey()] > 0
                    && ! isset($covered[$game->getKey()]),
            );
            if ($matches->isEmpty()) {
                continue;
            }

            try {
                $disk = Storage::disk($export->disk);
                if (! $disk->exists($export->manifest_key) || ! $disk->exists($export->object_key)
                    || $disk->size($export->object_key) !== $export->size_bytes) {
                    continue;
                }
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($matches as $game) {
                $covered[$game->getKey()] = true;
            }
        }

        return $covered;
    }
}
