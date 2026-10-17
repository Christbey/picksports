<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Scoring\CfbScoringFeatures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('exports only accepted sources available at the requested cutoff and preserves their actual availability', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subDay()]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL', 'venue_name' => 'Test Stadium', 'home_score' => 21, 'away_score' => 7]);
    foreach (['boxscore', 'plays'] as $component) {
        GameDataCheck::create(['game_id' => $game->id, 'component' => $component, 'source_hash' => str_repeat('a', 64), 'validator_version' => '2',
            'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'source_path' => 'fixture', 'accepted_at' => now()]);
    }
    DB::table('cfb_drives')->insert(['game_id' => $game->id, 'source_revision' => str_repeat('a', 64), 'drive_key' => '1', 'offense_team_id' => $game->home_team_id,
        'quality' => 'complete', 'data' => json_encode(['offense_points' => 21, 'opponent_points' => 7, 'period' => 1,
            'normalizer_version' => CfbDriveBuilder::VERSION, 'score_ledger_revision' => str_repeat('a', 64)]), 'created_at' => now(), 'updated_at' => now()]);
    EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'cfb', 'phase' => 'pregame',
        'captured_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'cutoff_at' => $event->starts_at,
        'latest_source_available_at' => now()->subDays(2), 'content_hash' => str_repeat('f', 64),
        'inputs' => ['event' => ['season' => 2026, 'week' => 3, 'venue' => 'Frozen Stadium'],
            'home' => ['metrics' => ['fpi' => 5], 'injuries' => [['status' => 'out']]],
            'away' => ['metrics' => ['fpi' => 0]], 'football_signal_evidence' => ['unused_payload' => str_repeat('x', 1000000)]]]);
    $path = sys_get_temp_dir().'/cfb-export-'.Str::uuid().'.jsonl';
    $snapshotSortQueries = [];
    $snapshotPayloadQueries = [];
    DB::listen(function ($query) use (&$snapshotSortQueries, &$snapshotPayloadQueries) {
        if (str_contains($query->sql, 'event_input_snapshots') && ! str_contains($query->sql, 'order by')) {
            $snapshotPayloadQueries[] = $query->sql;
        }
        if (str_contains($query->sql, 'event_input_snapshots') && str_contains($query->sql, 'order by')) {
            $snapshotSortQueries[] = $query->sql;
        }
    });
    try {
        $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->addMinute()->toIso8601String(), '--output' => $path])->assertSuccessful();
        expect($snapshotSortQueries)->not->toBeEmpty();
        foreach ($snapshotSortQueries as $sql) {
            expect($sql)->toMatch('/select [\"`]id[\"`] from/');
        }
        expect($snapshotPayloadQueries)->not->toBeEmpty();
        foreach ($snapshotPayloadQueries as $sql) {
            expect($sql)->toContain('json_extract')->not->toContain('select *');
        }
        $row = json_decode(trim(file_get_contents($path)), true);
        expect($row['game_id'])->toBe($game->id)->and($row['source_available_at'])->toBe(now()->toIso8601String())
            ->and($row['snapshot_hash'])->toBe(str_repeat('f', 64))
            ->and($row['context']['home']['out_records'])->toBe(1)
            ->and($row['venue'])->toBe('Frozen Stadium')->and($row['venue_source'])->toBe('original_pregame_snapshot');
        $manifest = json_decode(file_get_contents($path.'.manifest.json'), true);
        expect($manifest['scan']['scanned_games'])->toBe(1)->and($manifest['scan']['has_more'])->toBeFalse();
        expect($manifest['feature_policy'])->toBe(CfbScoringFeatures::POLICY)
            ->and($manifest['signal_families'])->not->toContain('market_spread');
        $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->addMinute()->toIso8601String(), '--output' => $path])->assertFailed();
        $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->subMinute()->toIso8601String(), '--output' => $path.'.old'])->assertExitCode(2);
    } finally {
        foreach ([$path, $path.'.manifest.json', $path.'.old', $path.'.old.manifest.json'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('rejects incomplete or future test windows before exporting', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
    $options = ['--from-season' => 2025, '--to-season' => 2026, '--as-of' => now()->toIso8601String(),
        '--output' => sys_get_temp_dir().'/cfb-invalid-'.Str::uuid().'.jsonl', '--test-from' => '2026-08-31T00:00:00Z'];
    $this->artisan('cfb:export-scoring-training', $options)->assertFailed();
    $this->artisan('cfb:export-scoring-training', [...$options, '--test-until' => '2026-10-19T00:00:00Z'])->assertFailed();
    expect(file_exists($options['--output']))->toBeFalse();
});

it('resumes bounded export scans without skipping excluded games', function () {
    Storage::fake('local');
    $games = Game::factory()->count(3)->create(['season' => 2026, 'status' => 'STATUS_FINAL',
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $path = sys_get_temp_dir().'/cfb-bounded-'.Str::uuid().'.jsonl';
    $options = ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->addMinute()->toIso8601String(), '--max-games' => 2];
    try {
        $this->artisan('cfb:export-scoring-training', [...$options, '--output' => $path])->assertExitCode(2);
        $first = json_decode(file_get_contents($path.'.manifest.json'), true);
        expect($first['scan']['scanned_games'])->toBe(2)->and($first['scan']['last_game_id'])->toBe($games[1]->id)
            ->and($first['scan']['has_more'])->toBeTrue();
        $this->artisan('cfb:export-scoring-training', [...$options, '--after-game-id' => $first['scan']['last_game_id'], '--output' => $path.'.next'])->assertExitCode(2);
        $second = json_decode(file_get_contents($path.'.next.manifest.json'), true);
        expect($second['scan']['scanned_games'])->toBe(1)->and($second['scan']['last_game_id'])->toBe($games[2]->id)
            ->and($second['scan']['has_more'])->toBeFalse();
    } finally {
        foreach ([$path, $path.'.manifest.json', $path.'.next', $path.'.next.manifest.json'] as $file) {
            @unlink($file);
        }
    }
});
