<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
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
    $path = sys_get_temp_dir().'/cfb-export-'.Str::uuid().'.jsonl';
    $snapshotSortQueries = [];
    DB::listen(function ($query) use (&$snapshotSortQueries) {
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
        $row = json_decode(trim(file_get_contents($path)), true);
        expect($row['game_id'])->toBe($game->id)->and($row['source_available_at'])->toBe(now()->toIso8601String())
            ->and($row['snapshot_hash'])->toBeNull()
            ->and($row['context']['home']['out_records'])->toBeNull()
            ->and($row['venue'])->toBe('Test Stadium')->and($row['venue_source'])->toBe('retrospective_game_metadata');
        $manifest = json_decode(file_get_contents($path.'.manifest.json'), true);
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
