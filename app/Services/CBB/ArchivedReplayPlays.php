<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Models\DatasetExportManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ArchivedReplayPlays
{
    private const MAX_COMPRESSED_BYTES = 2 * 1024 * 1024;

    private const MAX_JSON_BYTES = 8 * 1024 * 1024;

    /** @var array<int, array{disk: string, key: string, sha256: string, count: int, season: int}> */
    private array $games = [];

    private ?string $loadedShard = null;

    private array $loadedGames = [];

    public function loadIndex(?int $season): void
    {
        $this->games = [];
        $this->loadedShard = null;
        $this->loadedGames = [];

        $exports = DatasetExportManifest::query()
            ->where('dataset', 'historical-plays')->where('sport', 'cbb')
            ->where('format', 'parquet')->where('source_table', 'cbb_plays')
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->lazyByIdDesc(5);

        foreach ($exports as $export) {
            $shards = $export->metadata['replay_shards_v1'] ?? [];
            $counts = $export->metadata['verified_game_play_counts'] ?? [];
            foreach ($shards as $shard) {
                $sha256 = $shard['sha256'];
                if (! preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
                    throw new RuntimeException('Invalid CBB replay shard checksum.');
                }

                foreach ($shard['game_ids'] as $gameId) {
                    if (! is_int($counts[$gameId] ?? null) || $counts[$gameId] < 1) {
                        throw new RuntimeException('Invalid CBB replay archive game count.');
                    }

                    $this->games[$gameId] ??= [
                        'disk' => $export->disk,
                        'key' => dirname($export->object_key)."/replay/{$sha256}.json.gz",
                        'sha256' => $sha256,
                        'count' => $counts[$gameId],
                        'season' => $export->season,
                    ];
                }
            }
        }
    }

    /** @return list<int> */
    public function gameIds(): array
    {
        return array_keys($this->games);
    }

    public function covers(Game $game): bool
    {
        return ($this->games[$game->id]['season'] ?? null) === (int) $game->season;
    }

    public function scoredPlays(Game $game): Collection
    {
        $entry = $this->games[$game->id];
        $shard = $entry['disk'].':'.$entry['key'];
        if ($this->loadedShard !== $shard) {
            $this->loadedGames = $this->readShard($entry);
            $this->loadedShard = $shard;
        }

        $plays = $this->loadedGames[$game->id] ?? [];
        if (count($plays) !== $entry['count']) {
            throw new RuntimeException("CBB replay archive has incomplete plays for game {$game->id}.");
        }

        return collect($plays)
            ->filter(fn (array $play): bool => $play[4] !== null && $play[5] !== null)
            ->sortBy([fn (array $a, array $b): int => (($b[1] === null) <=> ($a[1] === null)) ?: ($a[1] <=> $b[1]) ?: ($a[0] <=> $b[0])])
            ->map(fn (array $play): object => (object) [
                'period' => $play[2],
                'clock' => $play[3],
                'home_score' => $play[4],
                'away_score' => $play[5],
            ])->values();
    }

    private function readShard(array $entry): array
    {
        $stream = Storage::disk($entry['disk'])->readStream($entry['key']);
        if (! is_resource($stream)) {
            throw new RuntimeException('CBB replay archive shard is missing.');
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_COMPRESSED_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (! is_string($bytes) || strlen($bytes) > self::MAX_COMPRESSED_BYTES || hash('sha256', $bytes) !== $entry['sha256']) {
            throw new RuntimeException('CBB replay archive shard failed checksum verification.');
        }

        $decoded = gzdecode($bytes, self::MAX_JSON_BYTES);
        if ($decoded === false) {
            throw new RuntimeException('CBB replay archive shard could not be decompressed.');
        }

        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }
}
