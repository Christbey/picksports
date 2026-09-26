<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\NflverseArchiveRepairPlanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function legacyNflverseSnapshot(Game $game, float $source = 6.5): GameOddsSnapshot
{
    return GameOddsSnapshot::create(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'source' => 'nflverse', 'bookmaker_key' => 'nflverse_closing', 'bookmaker_title' => 'nflverse closing line',
        'captured_at' => '2025-09-04 20:15:00', 'commence_time' => '2025-09-04 20:20:00', 'payload_hash' => str_repeat('a', 64),
        'market_context' => ['spread_line' => $source, 'source' => 'nflverse_schedules'],
        'odds_data' => ['home_team' => 'KC', 'away_team' => 'DEN', 'bookmakers' => [['key' => 'nflverse_closing',
            'markets' => [['key' => 'spreads', 'outcomes' => [['name' => 'KC', 'point' => $source, 'price' => -112], ['name' => 'DEN', 'point' => -$source, 'price' => -108]]]]]]]]);
}

it('proposes source-based sign correction without changing originals', function ($source) {
    $game = Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2025]);
    $snapshot = legacyNflverseSnapshot($game, $source);
    $before = $snapshot->fresh()->getRawOriginal();
    DB::enableQueryLog();
    try {
        $report = (new NflverseArchiveRepairPlanner)->run(2025, 2025, true);
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|alter|truncate)\b/i', $q['query']));
        expect($writes)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
    }
    expect($report['snapshots'])->toBe(1)->and($report['apply_allowed'])->toBeFalse()
        ->and($report['items'][0]['proposed_home_handicap'])->toBe(-$source)
        ->and(data_get($report['items'][0], 'proposed_spread_payload.bookmakers.0.markets.0.outcomes.0.price'))->toBe(-112)
        ->and($report['items'][0]['original']['odds_data'])->toBe($snapshot->odds_data)
        ->and($report['items'][0]['timezone_requires_original_source'])->toBeTrue()
        ->and($snapshot->fresh()->getRawOriginal())->toBe($before);
})->with([6.5, -3.5, 0.0]);

it('rejects ambiguous outcomes rather than guessing a correction', function () {
    $snapshot = legacyNflverseSnapshot(Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2025]));
    $data = $snapshot->odds_data;
    $data['bookmakers'][0]['markets'][0]['outcomes'][1]['name'] = 'KC';
    $snapshot->odds_data = $data;
    expect((new NflverseArchiveRepairPlanner)->inspect($snapshot)['status'])->toBe('blocked_ambiguous_teams');
});

it('does not propose a second sign flip for normalized archives', function () {
    $snapshot = legacyNflverseSnapshot(Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2025]));
    $first = (new NflverseArchiveRepairPlanner)->inspect($snapshot);
    $snapshot->odds_data = $first['proposed_spread_payload'];
    $snapshot->market_context = $snapshot->market_context + ['normalization_version' => 'nflverse_schedule_v2'];
    expect((new NflverseArchiveRepairPlanner)->inspect($snapshot)['status'])->toBe('already_normalized');
});

it('requires an explicit repair scope and leaves out other seasons and sources', function () {
    legacyNflverseSnapshot(Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2024]));
    legacyNflverseSnapshot(Game::factory()->for(Team::factory(), 'homeTeam')->for(Team::factory(), 'awayTeam')->create(['season' => 2025]));
    expect(Artisan::call('nfl:plan-nflverse-archive-repair'))->toBe(2);
    expect(Artisan::call('nfl:plan-nflverse-archive-repair', ['--from-season' => 2025, '--to-season' => 2025]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['snapshots'])->toBe(1);
});

it('blocks ordinary reimport of legacy archives before changing games or evidence', function () {
    $home = Team::factory()->create(['abbreviation' => 'KC']);
    $away = Team::factory()->create(['abbreviation' => 'DEN']);
    $game = Game::factory()->create(['season' => 2025, 'nflverse_game_id' => '2025_01_DEN_KC',
        'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $snapshot = legacyNflverseSnapshot($game);
    $beforeGame = $game->fresh()->getRawOriginal();
    $beforeSnapshot = $snapshot->fresh()->getRawOriginal();
    $file = tempnam(sys_get_temp_dir(), 'nfl-legacy-');
    File::put($file, "game_id,season,game_type,week,gameday,gametime,away_team,home_team,home_score,away_score,spread_line\n2025_01_DEN_KC,2025,REG,1,2025-09-04,20:20,DEN,KC,24,17,6.5\n");
    try {
        expect(Artisan::call('nfl:import-nflverse-schedules', ['file' => $file, '--dry-run' => true]))->toBe(1);
        expect(Artisan::call('nfl:import-nflverse-schedules', ['file' => $file]))->toBe(1);
        expect(Artisan::output())->toContain('Preserved 1 games with legacy nflverse archives');
        expect($game->fresh()->getRawOriginal())->toBe($beforeGame)
            ->and($snapshot->fresh()->getRawOriginal())->toBe($beforeSnapshot)
            ->and(GameOddsSnapshot::count())->toBe(1);
    } finally {
        File::delete($file);
    }
});
