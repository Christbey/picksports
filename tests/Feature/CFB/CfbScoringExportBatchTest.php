<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\Data\CfbDriveBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('bounds accepted-source and affiliation reads while preserving per-game export evidence', function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameIds = [];
    foreach (range(1, 10) as $index) {
        $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subDay()]);
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL']);
        $gameIds[] = $game->id;
        $hash = hash('sha256', 'game-'.$index);
        foreach (['boxscore', 'plays'] as $component) {
            GameDataCheck::create(['game_id' => $game->id, 'component' => $component, 'source_hash' => $hash, 'validator_version' => '2', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'source_path' => 'fixture', 'accepted_at' => now()]);
            GameDataCheck::create(['game_id' => $game->id, 'component' => $component, 'source_hash' => str_repeat('b', 64), 'validator_version' => '2', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'source_path' => 'future-fixture', 'accepted_at' => now()->addDays(2)]);
        }
        DB::table('cfb_drives')->insert(['game_id' => $game->id, 'source_revision' => $hash, 'drive_key' => '1', 'offense_team_id' => $home->id, 'quality' => 'complete', 'data' => json_encode(['offense_points' => 7, 'opponent_points' => 0, 'period' => 1, 'normalizer_version' => CfbDriveBuilder::VERSION, 'score_ledger_revision' => $hash]), 'created_at' => now(), 'updated_at' => now()]);
    }
    $counts = ['source' => 0, 'affiliation' => 0];
    DB::listen(function ($query) use (&$counts) {
        if (! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }
        if (str_contains($query->sql, 'cfb_game_data_checks')) {
            $counts['source']++;
        }
        if (str_contains($query->sql, 'cfb_team_season_affiliations')) {
            $counts['affiliation']++;
        }
    });
    $path = sys_get_temp_dir().'/cfb-batch-export-'.Str::uuid().'.jsonl';
    try {
        $this->artisan('cfb:export-scoring-training', ['--from-season' => 2026, '--to-season' => 2026, '--as-of' => now()->addMinute()->toIso8601String(), '--output' => $path])->assertSuccessful();
        $rows = collect(file($path, FILE_IGNORE_NEW_LINES))->map(fn ($line) => json_decode($line, true));
        expect($rows->pluck('game_id')->all())->toBe($gameIds)
            ->and($rows->pluck('source_hashes.plays')->all())->toBe(collect(range(1, 10))->map(fn ($index) => hash('sha256', 'game-'.$index))->all())
            ->and($counts)->toBe(['source' => 1, 'affiliation' => 1]);
    } finally {
        @unlink($path);
        @unlink($path.'.manifest.json');
    }
});
