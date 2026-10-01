<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflMatchupSignalCatalog;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use Illuminate\Support\Facades\DB;

function matchupSignalLeague(int $teamCount = 32): array
{
    $teams = Team::factory()->count($teamCount)->create();
    $games = [];
    $plays = [];
    for ($week = 1; $week <= 3; $week++) {
        for ($index = 0; $index < $teamCount / 2; $index++) {
            $opponent = $teamCount - $index - 1;
            $game = Game::factory()->create([
                'home_team_id' => $teams[$index]->id, 'away_team_id' => $teams[$opponent]->id,
                'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'week' => $week,
                'game_date' => "2026-09-0{$week}", 'game_time' => '17:00:00',
                'home_score' => $index, 'away_score' => $opponent,
            ]);
            $games[] = $game;
            foreach ([[$index, $opponent], [$opponent, $index]] as [$offense, $defense]) {
                for ($play = 0; $play < 40; $play++) {
                    $plays[] = [
                        'nflverse_play_key' => "{$game->id}-{$offense}-{$play}", 'nfl_game_id' => $game->id,
                        'possession_team_id' => $teams[$offense]->id, 'defense_team_id' => $teams[$defense]->id,
                        'play_type' => $play < 20 ? 'pass' : 'run', 'epa' => ($offense - 16) / 100,
                        'yards_gained' => $offense / 2, 'is_sack' => $play === 0,
                    ];
                }
            }
        }
    }
    foreach (array_chunk($plays, 100) as $chunk) {
        DB::table('nflverse_pbp_plays')->insert($chunk);
    }
    $target = Game::factory()->create([
        'home_team_id' => $teams[$teamCount - 1]->id, 'away_team_id' => $teams[$teamCount - 2]->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-20', 'game_time' => '17:00:00',
    ]);

    return [$target, $teams, $games];
}

function matchupSignal(array $result, int $id, int $offense): array
{
    return collect($result['signals'])->first(fn (array $row): bool => $row['id'] === $id && $row['offense_team_id'] === $offense);
}

it('preserves all supplied catalog IDs without inventing missing definitions', function () {
    $entries = app(NflMatchupSignalCatalog::class)->entries();
    expect(array_column($entries, 'id'))->toBe(range(1, 353))
        ->and($entries[352]['reason'])->toContain('Incomplete')
        ->and($entries[194]['support'])->toBe('unavailable')
        ->and($entries[194]['reason'])->toContain('charting');
});

it('computes split success explosives and situational EPA without guessing missing context', function () {
    [$target] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['down' => 1, 'yardline_100' => 20, 'yards_to_go' => 2]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['yards_gained' => 20]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['yards_gained' => 9]);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    expect($result['summary']['supported_rules'])->toBe(98)
        ->and($result['signals'])->toHaveCount(196)
        ->and(matchupSignal($result, 59, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 61, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 110, $target->home_team_id)['evidence']['offense']['value'])->toBe(0.0)
        ->and(matchupSignal($result, 32, $target->home_team_id)['evidence']['offense']['value'])->toBe(0.5)
        ->and(matchupSignal($result, 77, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(.15, .00001)
        ->and(matchupSignal($result, 80, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(.15, .00001)
        ->and(matchupSignal($result, 127, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 78, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['yards_gained' => 19, 'down' => null, 'yardline_100' => null]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['yards_gained' => 10]);
    $result = $service->build($target);
    expect(matchupSignal($result, 61, $target->home_team_id)['evidence']['offense']['value'])->toBe(0.0)
        ->and(matchupSignal($result, 110, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 77, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 80, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 26, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('evaluates passing yards per attempt without including sacks runs or missing yardage as zeros', function () {
    [$target] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->where('is_sack', true)->update(['yards_gained' => -99]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['yards_gained' => 99]);
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 63, $target->home_team_id);
    expect($signal['evidence']['offense']['value'])->toBe(15.5)
        ->and($signal['evidence']['offense']['plays'])->toBe(57)
        ->and($signal['evidence']['league_teams'])->toBe(32)
        ->and($signal['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)
        ->where('play_type', 'pass')->where('is_sack', false)->update(['yards_gained' => 0]);
    $signal = matchupSignal($service->build($target), 64, $target->home_team_id);
    expect($signal['evidence']['offense']['value'])->toBe(0.0)
        ->and($signal['evidence']['offense']['eligible'])->toBeTrue();
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)
        ->where('play_type', 'pass')->where('is_sack', false)->update(['yards_gained' => null]);
    $signal = matchupSignal($service->build($target), 64, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['value'])->toBeNull();
});

it('uses previous season only when explicitly requested and never blends seasons', function () {
    [$target, , $games] = matchupSignalLeague();
    foreach ($games as $game) {
        $game->update(['season' => 2025, 'game_date' => '2025-09-'.str_pad((string) $game->week, 2, '0', STR_PAD_LEFT)]);
    }
    $service = app(NflMatchupSignalService::class);
    $current = $service->build($target);
    $previous = $service->build($target, 'previous_season');
    expect($current['signals'][0]['status'])->toBe('insufficient_data')
        ->and($previous['season'])->toBe(2025)
        ->and($previous['target_season'])->toBe(2026)
        ->and($previous['signals'][0]['status'])->toBe('matched')
        ->and(array_unique(array_values($previous['prediction_effect'])))->toBe([false]);
});

it('ranks offense higher and EPA allowed lower with grouped queries and independent directions', function () {
    [$target, $teams] = matchupSignalLeague();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(NflMatchupSignalService::class)->build($target);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $signal = matchupSignal($result, 1, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['rank'])->toBe(1)
        ->and($signal['evidence']['defense']['rank'])->toBe(2)
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.15, .00001)
        ->and($signal['evidence']['defense']['value'])->toEqualWithDelta(-.15, .00001)
        ->and(matchupSignal($result, 2, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 51, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 101, $target->home_team_id)['status'])->toBe('matched')
        ->and($result['predictive_weight'])->toBe(0)
        ->and(count($queries))->toBe(3);
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[1]->id;
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 4, $target->home_team_id)['status'])->toBe('matched');
});

it('keeps real zero EPA and zero points but does not turn missing EPA into zero', function () {
    [$target, $teams] = matchupSignalLeague();
    $target->home_team_id = $teams[16]->id;
    $target->away_team_id = $teams[0]->id;
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 1, $teams[16]->id)['evidence']['offense']['value'])->toBe(0.0)
        ->and(matchupSignal($result, 38, $teams[0]->id)['evidence']['offense']['value'])->toBe(0.0)
        ->and(matchupSignal($result, 1, $teams[16]->id)['status'])->not->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[16]->id)->update(['epa' => null]);
    $result = app(NflMatchupSignalService::class)->build($target);
    $signal = matchupSignal($result, 1, $teams[16]->id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['value'])->toBeNull()
        ->and($signal['evidence']['offense']['games'])->toBe(0);
});

it('evaluates two complete games per team but still rejects a one-game sample', function () {
    [$target] = matchupSignalLeague();
    Game::query()->where('season', 2026)->where('week', 3)->where('status', 'STATUS_FINAL')
        ->update(['status' => 'STATUS_SCHEDULED']);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    $signal = matchupSignal($result, 1, $target->home_team_id);
    expect($result['minimum_games'])->toBe(2)
        ->and($signal['evidence']['offense']['games'])->toBe(2)
        ->and($signal['evidence']['league_teams'])->toBe(32)
        ->and($signal['status'])->toBe('matched');
    Game::query()->where('season', 2026)->where('week', 2)->where('status', 'STATUS_FINAL')
        ->update(['status' => 'STATUS_SCHEDULED']);
    $signal = matchupSignal($service->build($target), 1, $target->home_team_id);
    expect($signal['evidence']['offense']['games'])->toBe(1)
        ->and($signal['status'])->toBe('insufficient_data')
        ->and($signal['reason'])->toContain('two qualifying games');
});

it('withholds league rank signals when the league or a team’s historical play coverage is incomplete', function () {
    [$target, $teams, $games] = matchupSignalLeague(30);
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 1, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['rank'])->toBeNull()
        ->and(matchupSignal($result, 1, $target->home_team_id)['reason'])->toContain('32 teams');
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[0]->id)->delete();
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['games'])->toBe(2)
        ->and(matchupSignal($result, 1, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('excludes target future nonfinal preseason previous season and not-yet-completed same-day results', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    $before = app(NflMatchupSignalService::class)->build($target);
    foreach ([
        ['game_date' => '2026-09-21'],
        ['game_date' => '2026-09-19', 'status' => 'STATUS_IN_PROGRESS'],
        ['game_date' => '2026-09-19', 'season_type' => '1'],
        ['game_date' => '2025-09-19', 'season' => 2025],
        ['game_date' => '2026-09-20', 'game_time' => '16:00:00'],
    ] as $overrides) {
        Game::factory()->create(array_merge([
            'home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
            'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
            'game_time' => '17:00:00', 'home_score' => 100, 'away_score' => 100,
        ], $overrides));
    }
    $target->update(['status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 100]);
    $after = app(NflMatchupSignalService::class)->build($target);
    expect($after['signals'])->toBe($before['signals']);
});

it('does not classify a boundary-wide tie as a top five team or use no-play rows', function () {
    [$target, $teams] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['epa' => 0]);
    DB::table('nflverse_pbp_plays')->insert([
        'nflverse_play_key' => 'no-play-must-ignore',
        'nfl_game_id' => DB::table('nflverse_pbp_plays')->value('nfl_game_id'),
        'possession_team_id' => $teams[0]->id, 'defense_team_id' => $teams[31]->id,
        'play_type' => 'no_play', 'epa' => 500, 'yards_gained' => 500,
    ]);
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 1, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['rank_end'])->toBe(32)
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['value'])->toBe(0.0);
});

it('requires a known kickoff time and a regular-season target', function () {
    [$target] = matchupSignalLeague(2);
    $target->game_time = null;
    $result = app(NflMatchupSignalService::class)->build($target);
    expect($result['cutoff_at'])->toBeNull()
        ->and($result['signals'][0]['reason'])->toContain('cutoff');
    $target->game_time = '17:00:00';
    $target->season_type = '1';
    $result = app(NflMatchupSignalService::class)->build($target);
    expect($result['signals'][0]['reason'])->toContain('regular-season');
});

it('rejects negative scoring and incomplete per-game play coverage', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    $games[0]->update(['away_score' => -1]);
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[0]->id)
        ->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')->update(['epa' => null]);
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 38, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 1, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['games'])->toBe(2);
});

it('pools rare opportunities without treating absent opportunities as missing data', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['down' => 2, 'yardline_100' => 50, 'yards_to_go' => 10]);
    $laterGames = collect($games)->filter(fn ($game) => $game->week > 1)->pluck('id');
    DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $laterGames)->where('play_type', 'pass')
        ->update(['down' => 3, 'yardline_100' => 10, 'yards_to_go' => 2]);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([78, 79, 80, 127, 128] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->not->toBe('insufficient_data')
            ->and($signal['evidence']['offense']['games'])->toBe(3)
            ->and($signal['evidence']['league_teams'])->toBe(32);
    }
    $firstGame = collect($games)->first(fn ($game) => $game->home_team_id === $teams[0]->id);
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $firstGame->id)->where('play_type', 'pass')
        ->update(['yardline_100' => null]);
    expect(matchupSignal($service->build($target), 80, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('does not rank pooled situations with too few season opportunities', function () {
    [$target, , $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['down' => 2, 'yardline_100' => 50, 'yards_to_go' => 10]);
    foreach ($games as $game) {
        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $id = DB::table('nflverse_pbp_plays')->where('nfl_game_id', $game->id)
                ->where('possession_team_id', $teamId)->where('play_type', 'pass')->value('id');
            DB::table('nflverse_pbp_plays')->where('id', $id)->update(['down' => 3]);
        }
    }
    $signal = matchupSignal(app(NflMatchupSignalService::class)->build($target), 78, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['plays'])->toBe(3)
        ->and($signal['evidence']['offense']['minimum_plays'])->toBe(6);
});

it('excludes two point tries rather than treating their absent down as missing scrimmage data', function () {
    [$target, , $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['down' => 3, 'yardline_100' => 10, 'yards_to_go' => 2]);
    $service = app(NflMatchupSignalService::class);
    $before = matchupSignal($service->build($target), 78, $target->home_team_id);
    $game = collect($games)->first(fn ($game) => $game->away_team_id === $target->home_team_id);
    $row = (array) DB::table('nflverse_pbp_plays')->where('nfl_game_id', $game->id)
        ->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')->first();
    unset($row['id']);
    $row['description'] = 'TWO-POINT CONVERSION ATTEMPT. Pass incomplete. ATTEMPT FAILS.';
    $row['down'] = null;
    $row['yards_to_go'] = 0;
    $row['epa'] = -100;
    foreach (range(1, 5) as $i) {
        $row['nflverse_play_key'] = 'conversion-'.$i;
        DB::table('nflverse_pbp_plays')->insert($row);
    }
    $after = matchupSignal($service->build($target), 78, $target->home_team_id);
    expect($after)->toBe($before);
});

it('evaluates points per drive from possession scores including conversions without counting opponent scores', function () {
    [$target, $teams] = matchupSignalLeague();
    // Eight complete drives per game, each with five plays. The first drive
    // scores seven points, the other seven score zero.
    $rows = DB::table('nflverse_pbp_plays')->get();
    foreach ($rows as $index => $row) {
        $play = (int) substr($row->nflverse_play_key, strrpos($row->nflverse_play_key, '-') + 1);
        DB::table('nflverse_pbp_plays')->where('id', $row->id)->update([
            'fixed_drive' => intdiv($play, 5) + 1,
            'fixed_drive_result' => $play < 5 ? 'Touchdown' : 'Opp touchdown',
            'possession_score_before' => $play < 5 ? 0 : 7,
            'possession_score_after' => 7,
        ]);
    }
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 15, $target->home_team_id);
    expect($signal['evidence']['offense']['value'])->toBe(0.875)
        ->and($signal['evidence']['league_teams'])->toBe(32)
        ->and($signal['status'])->not->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->update(['fixed_drive' => null]);
    expect(matchupSignal($service->build($target), 15, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('evaluates provider passing splits and mixed offensive and defensive metrics without swapping directions', function () {
    [$target] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['first_down' => 1, 'air_yards' => 25, 'cpoe' => 5,
        'shotgun' => 1, 'complete_pass' => 1, 'pass_touchdown' => 0, 'pass_oe' => 12,
        'is_interception' => 0, 'down' => 1, 'win_probability' => .5, 'game_seconds_remaining' => 1800]);
    $result = app(NflMatchupSignalService::class)->build($target);
    $signal = matchupSignal($result, 65, $target->home_team_id);
    expect($signal['evidence']['offense_metric'])->toBe('cpoe')
        ->and($signal['evidence']['defense_metric'])->toBe('completion_rate')
        ->and($signal['evidence']['offense']['value'])->toBe(5.0)
        ->and($signal['evidence']['defense']['value'])->toBe(1.0)
        ->and($signal['status'])->not->toBe('insufficient_data');
    expect(matchupSignal($result, 67, $target->home_team_id)['evidence']['offense']['plays'])->toBe(57)
        ->and(matchupSignal($result, 23, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 90, $target->home_team_id)['evidence']['offense']['value'])->toBe(.5)
        ->and(matchupSignal($result, 93, $target->home_team_id)['evidence']['offense']['value'])->toBe(12.0);
    DB::table('nflverse_pbp_plays')->update(['shotgun' => null, 'first_down' => null, 'cpoe' => null]);
    $missing = app(NflMatchupSignalService::class)->build($target);
    foreach ([23, 65, 75, 76] as $id) {
        expect(matchupSignal($missing, $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('ranks low interception offense and high interception defense as better', function () {
    [$target, $teams] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['is_interception' => 0]);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[0]->id)->where('play_type', 'pass')->update(['is_interception' => 1]);
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[31]->id;
    $s = matchupSignal(app(NflMatchupSignalService::class)->build($target), 83, $target->home_team_id);
    expect($s['evidence']['offense']['rank'])->toBe(32)
        ->and($s['evidence']['defense']['rank'])->toBe(1)
        ->and($s['status'])->toBe('matched');
});

it('requires a full trend sequence and excludes the current game from opponent adjustment', function () {
    [$target] = matchupSignalLeague();
    $result = app(NflMatchupSignalService::class)->build($target);
    expect(matchupSignal($result, 40, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($result, 42, $target->home_team_id)['status'])->toBe('insufficient_data');
    // Every fixture offense faces the same defense with unchanged EPA in all games.
    // Subtracting the opponent's other-game allowance must therefore equal zero.
    $adjusted = matchupSignal($result, 44, $target->home_team_id);
    expect($adjusted['evidence']['offense']['value'])->toEqualWithDelta(0, .000001)
        ->and($adjusted['evidence']['offense']['games'])->toBe(3);
});
