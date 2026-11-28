<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\ProviderSourceFile;
use App\Services\ProviderData\ProviderSourceStorage;
use App\Support\Json\JsonAllocationBudget;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Preserve explicit final box-score observations, not inferred participation or zeros. */
class NflBoxscoreSourceArchive
{
    public const MAXIMUM_BYTES = 8 * 1024 * 1024;

    public const MAXIMUM_TOKENS = 1000000;

    public const MAXIMUM_DEPTH = 64;

    public function __construct(private ProviderSourceStorage $sources) {}

    public function record(Game $game, array $summary): ?ProviderSourceFile
    {
        if (empty($summary['boxscore']['players'])) {
            return null;
        }
        if (data_get($summary, 'header.competitions.0.status.type.completed') !== true) {
            if ($game->status === 'STATUS_FINAL' || data_get($summary, 'header.competitions.0.status.type.name') === 'STATUS_FINAL') {
                throw new RuntimeException('Final NFL box-score source lacks confirmed completion.');
            }

            return null;
        }
        if ((string) data_get($summary, 'header.id') !== (string) $game->espn_event_id
            || (int) data_get($summary, 'header.season.year') !== (int) $game->season) {
            throw new RuntimeException('NFL box-score archive event/season identity mismatch.');
        }
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $expected = [(string) $game->homeTeam?->espn_id, (string) $game->awayTeam?->espn_id];
        $observed = array_map(fn ($team) => (string) data_get($team, 'team.id'), $summary['boxscore']['players']);
        sort($expected);
        sort($observed);
        if (count($expected) !== 2 || in_array('', $expected, true) || count(array_unique($expected)) !== 2 || $observed !== $expected) {
            throw new RuntimeException('NFL box-score archive team identity mismatch.');
        }
        // Exclude plays, news and retrieval time: identical box scores deduplicate across syncs.
        $payload = ['schema' => 'nfl-boxscore-evidence-v1', 'event_id' => (string) $game->espn_event_id,
            'header' => $summary['header'], 'boxscore' => $summary['boxscore']];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('NFL box-score archive exceeds the size limit.');
        }
        JsonAllocationBudget::inspect($json, self::MAXIMUM_BYTES, self::MAXIMUM_TOKENS, self::MAXIMUM_DEPTH, 'NFL box-score JSON');
        $compressed = gzencode($json, 6);
        if ($compressed === false || strlen($compressed) > self::MAXIMUM_BYTES) {
            throw new RuntimeException('NFL box-score archive compression failed.');
        }
        $directory = sys_get_temp_dir().'/nfl-boxscore-'.Str::uuid();
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private box-score staging directory.');
        }
        $path = $directory.'/boxscore.json.gz';
        try {
            if (file_put_contents($path, $compressed) !== strlen($compressed)) {
                throw new RuntimeException('NFL box-score staging write failed.');
            }

            return $this->sources->archive('espn', 'nfl-boxscore', $path, [
                'game_id' => $game->id, 'event_id' => (string) $game->espn_event_id, 'season' => (int) $game->season,
                'observed_at' => now()->toIso8601String(), 'purpose' => 'postgame_actual_evidence',
                'json_sha256' => hash('sha256', $json), 'schema' => 'nfl-boxscore-evidence-v1',
            ]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function read(ProviderSourceFile $source, Game $game): array
    {
        if ($source->provider !== 'espn' || $source->dataset !== 'nfl-boxscore'
            || (int) ($source->metadata['game_id'] ?? 0) !== (int) $game->id
            || (int) ($source->metadata['season'] ?? 0) !== (int) $game->season
            || (string) ($source->metadata['event_id'] ?? '') !== (string) $game->espn_event_id) {
            throw new RuntimeException('NFL box-score source does not match the requested game.');
        }
        $stream = Storage::disk($source->disk)->readStream($source->object_key);
        if (! is_resource($stream)) {
            throw new RuntimeException('NFL box-score source is unavailable.');
        }
        try {
            $compressed = stream_get_contents($stream, self::MAXIMUM_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($compressed === false || strlen($compressed) !== (int) $source->size_bytes
            || strlen($compressed) > self::MAXIMUM_BYTES || ! hash_equals($source->sha256, hash('sha256', $compressed))) {
            throw new RuntimeException('NFL box-score source checksum/size mismatch.');
        }
        $json = @gzdecode($compressed, self::MAXIMUM_BYTES + 1);
        if ($json === false || strlen($json) > self::MAXIMUM_BYTES
            || ! hash_equals((string) ($source->metadata['json_sha256'] ?? ''), hash('sha256', $json))) {
            throw new RuntimeException('NFL box-score JSON checksum/size mismatch.');
        }
        JsonAllocationBudget::inspect($json, self::MAXIMUM_BYTES, self::MAXIMUM_TOKENS, self::MAXIMUM_DEPTH, 'NFL box-score JSON');
        $payload = json_decode($json, true, self::MAXIMUM_DEPTH + 1, JSON_THROW_ON_ERROR);
        if (($payload['schema'] ?? null) !== 'nfl-boxscore-evidence-v1'
            || ($payload['event_id'] ?? null) !== (string) $game->espn_event_id
            || (int) data_get($payload, 'header.season.year') !== (int) $game->season
            || data_get($payload, 'header.competitions.0.status.type.completed') !== true) {
            throw new RuntimeException('NFL box-score payload identity mismatch.');
        }

        return $payload;
    }
}
