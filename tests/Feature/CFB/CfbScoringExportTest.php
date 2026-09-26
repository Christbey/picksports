<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('exports only accepted sources available at the requested cutoff and preserves their actual availability', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subDay()]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 21, 'away_score' => 7]);
    foreach (['boxscore', 'plays'] as $component) {
        GameDataCheck::create(['game_id' => $game->id, 'component' => $component, 'source_hash' => str_repeat('a', 64), 'validator_version' => '2',
            'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'source_path' => 'fixture', 'accepted_at' => now()]);
    }
    DB::table('cfb_drives')->insert(['game_id' => $game->id, 'source_revision' => str_repeat('a', 64), 'drive_key' => '1', 'offense_team_id' => $game->home_team_id,
        'quality' => 'complete', 'data' => json_encode(['offense_points' => 21, 'opponent_points' => 7, 'period' => 1]), 'created_at' => now(), 'updated_at' => now()]);
    $path = sys_get_temp_dir().'/cfb-export-'.Str::uuid().'.jsonl';
    try {
        $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->addMinute()->toIso8601String(), '--output' => $path])->assertSuccessful();
        $row = json_decode(trim(file_get_contents($path)), true);
        expect($row['game_id'])->toBe($game->id)->and($row['source_available_at'])->toBe(now()->toIso8601String())
            ->and($row['snapshot_hash'])->toBeNull();
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
