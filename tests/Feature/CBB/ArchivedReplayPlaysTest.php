<?php

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Prediction;
use App\Models\DatasetExportManifest;
use App\Services\CBB\ArchivedReplayPlays;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('replays archived score states with the same order and results as SQL', function () {
    Storage::fake('s3');
    $game = Game::factory()->create(['season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 80, 'away_score' => 75]);
    Prediction::factory()->create(['game_id' => $game->id]);
    $rows = [];
    foreach ([[2, 45], [1, 20], [2, 47], [null, 0], [0, 5], [3, null]] as [$sequence, $score]) {
        $play = Play::factory()->create([
            'game_id' => $game->id, 'sequence_number' => $sequence, 'period' => 1, 'clock' => '10:00',
            'home_score' => $score, 'away_score' => 5,
        ]);
        $rows[] = [$play->id, $sequence, 1, '10:00', $score, 5];
    }

    $options = ['--season' => 2026, '--limit' => 1, '--states-per-game' => 5, '--detailed' => true];
    expect(Artisan::call('cbb:backtest-live-from-plays', $options))->toBe(0);
    $baseline = Artisan::output();
    expect($baseline)->toContain('Winner Accuracy');

    $contents = gzencode(json_encode([$game->id => $rows], JSON_THROW_ON_ERROR));
    $sha = hash('sha256', $contents);
    $object = 'historical/cbb/part-00000.parquet';
    $shard = "historical/cbb/replay/{$sha}.json.gz";
    Storage::disk('s3')->put($shard, $contents);
    DatasetExportManifest::factory()->create([
        'dataset' => 'historical-plays', 'sport' => 'cbb', 'season' => 2026, 'format' => 'parquet',
        'source_table' => 'cbb_plays', 'disk' => 's3', 'object_key' => $object, 'row_count' => count($rows),
        'metadata' => [
            'verified_game_play_counts' => [$game->id => count($rows)],
            'replay_shards_v1' => [['sha256' => $sha, 'game_ids' => [$game->id]]],
        ],
    ]);

    // Live SQL takes priority even if an archive is registered.
    Storage::disk('s3')->put($shard, 'corrupt');
    expect(Artisan::call('cbb:backtest-live-from-plays', $options))->toBe(0)
        ->and(Artisan::output())->toBe($baseline);

    $game->plays()->delete();
    $reader = app(ArchivedReplayPlays::class);
    $reader->loadIndex(2026);
    expect(fn () => $reader->scoredPlays($game))->toThrow(RuntimeException::class, 'failed checksum verification');

    Storage::disk('s3')->put($shard, $contents);
    expect($reader->scoredPlays($game)->pluck('home_score')->all())->toBe([0, 5, 20, 45, 47])
        ->and(Artisan::call('cbb:backtest-live-from-plays', $options))->toBe(0)
        ->and(Artisan::output())->toBe($baseline);
});
