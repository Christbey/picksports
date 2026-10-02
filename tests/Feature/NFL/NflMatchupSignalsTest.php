<?php

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\DepthChartSnapshotEntry;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\PlayerInjurySnapshotEntry;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflMatchupSignalCatalog;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use App\Services\NFL\QuarterbackAvailability;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function matchupSignalLeague(int $teamCount = 32, int $weeks = 3): array
{
    $teams = Team::factory()->count($teamCount)->create();
    $games = [];
    $plays = [];
    for ($week = 1; $week <= $weeks; $week++) {
        for ($index = 0; $index < $teamCount / 2; $index++) {
            $opponent = $teamCount - $index - 1;
            $game = Game::factory()->create([
                'home_team_id' => $teams[$index]->id, 'away_team_id' => $teams[$opponent]->id,
                'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'week' => $week,
                'game_date' => "2026-09-0{$week}", 'game_time' => '17:00:00',
                'home_score' => $index, 'away_score' => $opponent, 'neutral_site' => false,
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
        'week' => 4, 'game_date' => '2026-09-20', 'game_time' => '17:00:00', 'neutral_site' => false,
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
        ->and($entries[212]['support'])->toBe('unavailable')
        ->and($entries[212]['reason'])->toContain('charting');
});

it('computes split success explosives and situational EPA without guessing missing context', function () {
    [$target] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['down' => 1, 'yardline_100' => 20, 'yards_to_go' => 2]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['yards_gained' => 20]);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['yards_gained' => 9]);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    expect($result['summary']['supported_rules'])->toBe(219)
        ->and($result['signals'])->toHaveCount(438)
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
        ->and(collect($queries)->filter(fn ($q) => str_contains($q['query'], 'nflverse_pbp_plays')))->toHaveCount(10);
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

it('requires charted play-action screen RPO and motion flags instead of treating unknown flags as false', function () {
    [$target] = matchupSignalLeague();
    $service = app(NflMatchupSignalService::class);
    foreach ([71, 73, 74, 277] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
    DB::table('nflverse_pbp_plays')->update(['ftn_is_play_action' => true, 'ftn_is_screen_pass' => true,
        'ftn_is_rpo' => true, 'ftn_is_motion' => true, 'ftn_n_defense_box' => 6]);
    $result = $service->build($target);
    foreach ([71, 73, 74, 277] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->not->toBe('insufficient_data');
    }
    expect(matchupSignal($result, 277, $target->home_team_id)['evidence']['offense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, 122, $target->home_team_id)['evidence']['defense']['value'])->toBe(6.0);
});

it('uses the selected quarterbacks own passing sample including sacks and refuses missing identities', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')->update(['passer_player_id' => '00-'.(1000 + $i)]);
    }
    $target->home_qb_id = '00-1031';
    $target->home_qb_name = 'Selected QB';
    $service = app(NflMatchupSignalService::class);
    $row = matchupSignal($service->build($target), 191, $target->home_team_id);
    expect($row['status'])->toBe('matched')
        ->and($row['evidence']['offense']['player_name'])->toBe('Selected QB')
        ->and($row['evidence']['offense']['plays'])->toBe(60)
        ->and($row['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and($row['evidence']['offense']['league_players'])->toBe(32);
    // A newly selected passer keeps his own sample, never the current team's .15 EPA.
    $target->home_qb_id = '00-1000';
    $row = matchupSignal($service->build($target), 193, $target->home_team_id);
    expect($row['status'])->toBe('matched')->and($row['evidence']['offense']['value'])->toEqualWithDelta(-.16, .000001);
    $target->home_qb_id = null;
    expect(matchupSignal($service->build($target), 191, $target->home_team_id)['status'])->toBe('insufficient_data');
    $target->home_qb_id = '00-1031';
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->update(['epa' => null]);
    expect(matchupSignal($service->build($target), 191, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('ranks home and road passing separately and excludes neutral sites and wrong target roles', function () {
    [$target, , $games] = matchupSignalLeague();
    foreach ($games as $game) {
        $copy = $game->replicate();
        $copy->home_team_id = $game->away_team_id;
        $copy->away_team_id = $game->home_team_id;
        $copy->espn_event_id = 'venue-'.$game->id;
        $copy->save();
        foreach (DB::table('nflverse_pbp_plays')->where('nfl_game_id', $game->id)->get() as $play) {
            $row = (array) $play;
            unset($row['id']);
            $row['nfl_game_id'] = $copy->id;
            $row['nflverse_play_key'] .= '-venue';
            DB::table('nflverse_pbp_plays')->insert($row);
        }
    }
    $service = app(NflMatchupSignalService::class);
    $row = matchupSignal($service->build($target), 98, $target->home_team_id);
    expect($row['evidence']['offense']['games'])->toBe(3)
        ->and($row['evidence']['offense']['league_teams'])->toBe(32)
        ->and($row['evidence']['offense']['plays'])->toBe(60)
        ->and($row['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and(matchupSignal($service->build($target), 97, $target->home_team_id)['status'])->toBe('not_matched');
    Game::whereIn('id', collect($games)->pluck('id'))->update(['neutral_site' => true]);
    expect(matchupSignal($service->build($target), 98, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('resolves a projected QB only through a recent game link and unambiguous ESPN to GSIS mapping', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')->update(['passer_player_id' => '00-'.(1000 + $i)]);
    }
    $snapshot = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(),
        'team_id' => $target->home_team_id, 'espn_team_id' => '123', 'season' => 2026, 'provider' => 'espn',
        'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'qb-depth'), 'entry_count' => 1]);
    DepthChartSnapshotEntry::create(['snapshot_id' => $snapshot->id, 'position_slot_key' => 'offense:QB',
        'position_code' => 'QB', 'depth_rank' => 1, 'espn_athlete_id' => '456', 'observed_at' => now()->subHour()]);
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $snapshot->id,
            'snapshot_uuid' => $snapshot->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $roster = ['nflverse_roster_key' => 'qb-mapping', 'season' => 2026, 'team_id' => $target->home_team_id,
        'position' => 'QB', 'espn_id' => '456', 'gsis_id' => '00-1031', 'full_name' => 'Projected Passer'];
    DB::table('nflverse_rosters')->insert($roster);
    $service = app(NflMatchupSignalService::class);
    $row = matchupSignal($service->build($target), 191, $target->home_team_id);
    expect($row['status'])->toBe('matched')
        ->and($row['evidence']['offense']['player_id'])->toBe('00-1031')
        ->and($row['evidence']['offense']['identity_status'])->toBe('projected_not_confirmed_starter');
    DB::table('nflverse_rosters')->insert([...$roster, 'nflverse_roster_key' => 'conflict', 'gsis_id' => '00-1000']);
    expect(matchupSignal($service->build($target), 191, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('uses charted blitzes for quarterback splits and treats unknown blitz flags as missing', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')->update(['passer_player_id' => '00-'.(1000 + $i)]);
    }
    $target->home_qb_id = '00-1031';
    DB::table('nflverse_pbp_plays')->update(['ftn_n_blitzers' => 0]);
    DB::table('nflverse_pbp_plays')->whereRaw('id % 2 = 0')->update(['ftn_n_blitzers' => 1]);
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->update(['ftn_n_blitzers' => 1]);
    $service = app(NflMatchupSignalService::class);
    $row = matchupSignal($service->build($target), 195, $target->home_team_id);
    expect($row['status'])->toBe('matched')
        ->and($row['evidence']['offense']['plays'])->toBe(30)
        ->and($row['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and($row['evidence']['defense']['value'])->toBe(1.0);
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->update(['ftn_n_blitzers' => null]);
    expect(matchupSignal($service->build($target), 195, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('measures equal-game EPA variability and ranks consistency in the same direction on both sides', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    foreach ($games as $game) {
        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $index = $teams->search(fn ($team) => $team->id === $teamId);
            DB::table('nflverse_pbp_plays')->where('nfl_game_id', $game->id)->where('possession_team_id', $teamId)
                ->update(['epa' => ($game->week - 2) * $index / 100]);
        }
    }
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[1]->id;
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    $consistent = matchupSignal($result, 34, $teams[0]->id);
    expect($consistent['status'])->toBe('matched')
        ->and($consistent['evidence']['offense']['value'])->toBe(0.0)
        ->and($consistent['evidence']['offense']['rank'])->toBe(1)
        ->and($consistent['evidence']['defense']['value'])->toEqualWithDelta(.30, .000001)
        ->and($consistent['evidence']['defense']['rank'])->toBe(31)
        ->and($consistent['evidence']['offense']['game_epa_values'])->toHaveCount(3)
        ->and(matchupSignal($result, 35, $teams[0]->id)['status'])->toBe('not_matched');
    $target->home_team_id = $teams[31]->id;
    $variable = matchupSignal($service->build($target), 35, $teams[31]->id);
    expect($variable['status'])->toBe('matched')
        ->and($variable['evidence']['offense']['value'])->toEqualWithDelta(.31, .000001)
        ->and($variable['evidence']['offense']['rank'])->toBe(32);

    // Duplicating one game's plays cannot give that game's mean extra weight.
    $oneGame = collect($games)->first(fn ($game) => $game->week === 1 && $game->away_team_id === $teams[31]->id);
    $copies = DB::table('nflverse_pbp_plays')->where('nfl_game_id', $oneGame->id)->get();
    foreach ($copies as $play) {
        $copy = (array) $play;
        unset($copy['id']);
        $copy['nflverse_play_key'] .= '-extra';
        DB::table('nflverse_pbp_plays')->insert($copy);
    }
    $variable = matchupSignal($service->build($target), 35, $teams[31]->id);
    expect($variable['evidence']['offense']['value'])->toEqualWithDelta(.31, .000001)
        ->and($variable['evidence']['offense']['games'])->toBe(3);
});

it('does not manufacture variance ranks from tied values incomplete games or partial leagues', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([34, 35] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('not_matched')
            ->and($signal['evidence']['offense']['rank'])->toBe(1)
            ->and($signal['evidence']['offense']['rank_end'])->toBe(32);
    }
    $target->update(['game_date' => '2026-09-03']);
    $signal = matchupSignal($service->build($target), 34, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['reason'])->toContain('three complete games');
    $target->update(['game_date' => '2026-09-20']);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[3]->id)->where('nfl_game_id', $games[3]->id)->update(['epa' => null]);
    $signal = matchupSignal($service->build($target), 34, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['league_teams'])->toBe(31)
        ->and($signal['evidence']['offense']['rank'])->toBeNull();
});

it('uses selected quarterback deep play-action and RPO samples with correct sack handling', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['passer_player_id' => '00-'.(1000 + $i), 'air_yards' => 20, 'ftn_is_play_action' => 1, 'ftn_is_rpo' => 1]);
    }
    $target->home_qb_id = '00-1031';
    $target->home_qb_name = 'Selected Deep Passer';
    $target->away_team_id = $teams[1]->id;
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([220 => 57, 225 => 60, 226 => 60] as $rule => $plays) {
        $signal = matchupSignal($result, $rule, $target->home_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['offense']['player_name'])->toBe('Selected Deep Passer')
            ->and($signal['evidence']['offense']['plays'])->toBe($plays)
            ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
            ->and($signal['evidence']['offense']['league_players'])->toBe(32);
    }
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->where('is_sack', true)->update(['epa' => 99]);
    $result = $service->build($target);
    expect(matchupSignal($result, 220, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and(matchupSignal($result, 225, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(5.0925, .000001)
        ->and(matchupSignal($result, 226, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(5.0925, .000001);
    $target->home_qb_id = '00-1000';
    $result = $service->build($target);
    foreach ([220, 225, 226] as $rule) {
        expect(matchupSignal($result, $rule, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(-.16, .000001);
    }
    $target->home_qb_id = null;
    foreach ([220, 225, 226] as $rule) {
        expect(matchupSignal($service->build($target), $rule, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('holds quarterback scheme splits when classification or cohort coverage is missing', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['passer_player_id' => '00-'.(1000 + $i), 'air_yards' => 20, 'ftn_is_play_action' => 1, 'ftn_is_rpo' => 1]);
    }
    $target->home_qb_id = '00-1031';
    $service = app(NflMatchupSignalService::class);
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->update(['air_yards' => null, 'ftn_is_play_action' => null, 'ftn_is_rpo' => null]);
    $result = $service->build($target);
    foreach ([220, 225, 226] as $rule) {
        expect(matchupSignal($result, $rule, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->update(['air_yards' => 20, 'ftn_is_play_action' => 1, 'ftn_is_rpo' => 1]);
    DB::table('nflverse_pbp_plays')->whereIn('passer_player_id', array_map(fn ($i) => '00-'.(1000 + $i), range(0, 8)))
        ->update(['air_yards' => 0, 'ftn_is_play_action' => 0, 'ftn_is_rpo' => 0]);
    $result = $service->build($target);
    foreach ([220, 225, 226] as $rule) {
        $signal = matchupSignal($result, $rule, $target->home_team_id);
        expect($signal['status'])->toBe('insufficient_data')
            ->and($signal['evidence']['offense']['league_players'])->toBe(23)
            ->and($signal['reason'])->toContain('24 qualified passers');
    }
});

it('isolates charted RPO passes and enforces split volume and EPA coverage', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)
            ->update(['passer_player_id' => '00-'.(1000 + $i), 'ftn_is_rpo' => 1]);
    }
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[1]->id;
    $service = app(NflMatchupSignalService::class);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['epa' => 99]);
    $passes = DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->where('play_type', 'pass');
    $passes->update(['ftn_is_rpo' => 0, 'epa' => 9]);
    $ids = (clone $passes)->orderBy('nfl_game_id')->orderBy('nflverse_play_key')->pluck('nflverse_play_key');
    $rpoIds = collect([$ids[0], $ids[1], $ids[2], $ids[3], $ids[20], $ids[21], $ids[22], $ids[40], $ids[41], $ids[42]]);
    DB::table('nflverse_pbp_plays')->whereIn('nflverse_play_key', $rpoIds)->update(['ftn_is_rpo' => 1, 'epa' => .5]);
    $signal = matchupSignal($service->build($target), 226, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['plays'])->toBe(10)
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.5, .000001);
    DB::table('nflverse_pbp_plays')->where('nflverse_play_key', $rpoIds->first())->update(['ftn_is_rpo' => 0]);
    $signal = matchupSignal($service->build($target), 226, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['plays'])->toBe(9);
    DB::table('nflverse_pbp_plays')->where('nflverse_play_key', $rpoIds->first())->update(['ftn_is_rpo' => 1, 'epa' => null]);
    $signal = matchupSignal($service->build($target), 226, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['games'])->toBe(2);
});

it('compares the selected quarterback trend with the opposing pass defense in chronological order', function () {
    [$target, $teams, $games] = matchupSignalLeague(32, 4);
    foreach ($games as $game) {
        DB::table('nflverse_pbp_plays')->where('nfl_game_id', $game->id)->where('play_type', 'pass')
            ->update(['epa' => $game->week / 10]);
    }
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[31]->id)->where('play_type', 'pass')
        ->update(['passer_player_id' => '00-1031']);
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[0]->id;
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    $signal = matchupSignal($result, 237, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['trend_game_values'])->toEqual([.1, .2, .3, .4])
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.1, .000001)
        ->and($signal['evidence']['offense']['plays'])->toBe(80)
        ->and($signal['evidence']['offense']['rank'])->toBeNull()
        ->and(matchupSignal($result, 238, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['epa' => DB::raw('-epa')]);
    $result = $service->build($target);
    expect(matchupSignal($result, 238, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 237, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[48]->id)->where('play_type', 'pass')->update(['epa' => -.3]);
    $result = $service->build($target);
    foreach ([237, 238] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('not_matched');
    }
    $target->home_qb_id = '00-9999';
    expect(matchupSignal($service->build($target), 237, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('requires four complete prior quarterback appearances without filling gaps from team passing', function () {
    [$target, $teams, $games] = matchupSignalLeague(32, 4);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[31]->id)->where('play_type', 'pass')
        ->update(['passer_player_id' => '00-1031']);
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[0]->id;
    $service = app(NflMatchupSignalService::class);
    $last = $games[48];
    $last->update(['game_date' => '2026-09-20']);
    $signal = matchupSignal($service->build($target), 237, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['games'])->toBe(3)
        ->and($signal['reason'])->toContain('full four- or five-game sequence');
    $last->update(['game_date' => '2026-09-04']);
    $plays = DB::table('nflverse_pbp_plays')->where('nfl_game_id', $last->id)->where('passer_player_id', '00-1031');
    $ids = (clone $plays)->limit(6)->pluck('nflverse_play_key');
    DB::table('nflverse_pbp_plays')->whereIn('nflverse_play_key', $ids)->update(['epa' => null]);
    expect(matchupSignal($service->build($target), 237, $target->home_team_id)['status'])->toBe('insufficient_data');
    $plays->update(['epa' => .5, 'passer_player_id' => '00-9999']);
    $signal = matchupSignal($service->build($target), 237, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['evidence']['offense']['games'])->toBe(3);
});

it('identifies rookie quarterbacks from the target roster without requiring prior passing appearances', function () {
    [$target, $teams] = matchupSignalLeague();
    $target->home_team_id = $teams[31]->id;
    $target->away_team_id = $teams[0]->id;
    $target->away_qb_id = '00-9000';
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('defense_team_id', $team->id)->where('play_type', 'pass')
            ->update(['ftn_n_blitzers' => 0]);
        $ids = DB::table('nflverse_pbp_plays')->where('defense_team_id', $team->id)->where('play_type', 'pass')
            ->limit($i + 1)->pluck('nflverse_play_key');
        DB::table('nflverse_pbp_plays')->whereIn('nflverse_play_key', $ids)->update(['ftn_n_blitzers' => 1]);
    }
    $roster = ['nflverse_roster_key' => 'rookie-matchup', 'season' => 2026, 'team_id' => $target->away_team_id,
        'position' => 'QB', 'gsis_id' => '00-9000', 'years_exp' => 0];
    DB::table('nflverse_rosters')->insert($roster);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([230, 232] as $id) {
        $signal = matchupSignal($result, $id, $target->away_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['offense']['years_experience'])->toBe(0)
            ->and($signal['evidence']['offense']['player_id'])->toBe('00-9000');
    }
    $target->neutral_site = true;
    expect(matchupSignal($service->build($target), 232, $target->away_team_id)['status'])->toBe('not_matched');
    $target->neutral_site = false;
    DB::table('nflverse_rosters')->update(['years_exp' => 1]);
    foreach ([230, 232] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->away_team_id)['status'])->toBe('not_matched');
    }
    DB::table('nflverse_rosters')->update(['years_exp' => null]);
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->update(['years_exp' => 0, 'season' => 2025]);
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->update(['season' => 2026, 'team_id' => $target->home_team_id]);
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->update(['team_id' => $target->away_team_id]);
    DB::table('nflverse_rosters')->insert([...$roster, 'nflverse_roster_key' => 'conflicting-experience', 'years_exp' => 2]);
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('nflverse_roster_key', 'conflicting-experience')->update(['years_exp' => null]);
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    $target->away_qb_id = null;
    expect(matchupSignal($service->build($target), 230, $target->away_team_id)['status'])->toBe('insufficient_data');
    $target->away_qb_id = '00-9000';
    DB::table('nflverse_rosters')->where('nflverse_roster_key', 'conflicting-experience')->delete();
    $availability = Mockery::mock(QuarterbackAvailability::class);
    $availability->shouldReceive('forGame')->andReturn([]);
    $availability->shouldReceive('excludes')->andReturn(true);
    app()->instance(QuarterbackAvailability::class, $availability);
    $signal = matchupSignal($service->build($target), 230, $target->away_team_id);
    expect($signal['status'])->toBe('insufficient_data')
        ->and($signal['reason'])->toContain('unavailable')
        ->and($signal['evidence']['offense']['value'])->toBeNull();
});

it('compares a verified lead receiver absence with top and bottom pass defenses', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'wr-chart')]);
    DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'wr', 'position_code' => 'WR',
        'depth_rank' => 1, 'espn_athlete_id' => '300', 'observed_at' => now()->subHour()]);
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $chart->id,
            'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $injury = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'observed_at' => now(), 'payload_hash' => hash('sha256', 'wr-injury')]);
    $report = PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => '300',
        'injury_key' => 'wr', 'status' => 'Out', 'observed_at' => now()]);
    $service = app(NflMatchupSignalService::class);
    $target->away_team_id = $teams[30]->id;
    $result = $service->build($target);
    expect(matchupSignal($result, 261, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 262, $target->home_team_id)['status'])->toBe('not_matched');
    $target->away_team_id = $teams[0]->id;
    $result = $service->build($target);
    expect(matchupSignal($result, 262, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 261, $target->home_team_id)['status'])->toBe('not_matched');
    $report->update(['status' => 'Active']);
    expect(matchupSignal($service->build($target), 262, $target->home_team_id)['status'])->toBe('not_matched');
});

it('requires a mapped game-selected backup in a fresh linked quarterback chart', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    $target->home_qb_id = '00-9000';
    $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'backup-qb')]);
    $starter = DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'qb', 'position_code' => 'QB',
        'depth_rank' => 1, 'espn_athlete_id' => '300', 'observed_at' => now()->subHour()]);
    $backup = DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'qb', 'position_code' => 'QB',
        'depth_rank' => 2, 'espn_athlete_id' => '301', 'observed_at' => now()->subHour()]);
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $chart->id,
            'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $roster = ['nflverse_roster_key' => 'backup-qb', 'season' => 2026, 'team_id' => $target->home_team_id,
        'position' => 'QB', 'espn_id' => '301', 'gsis_id' => '00-9000'];
    DB::table('nflverse_rosters')->insert($roster);
    combinedWinRateSnapshot($teams, true);
    $service = app(NflMatchupSignalService::class);
    $target->away_team_id = $teams[30]->id;
    $result = $service->build($target);
    expect(matchupSignal($result, 184, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 184, $target->home_team_id)['evidence']['additional_condition']['sample']['selected_depth_rank'])->toBe(2);
    expect(matchupSignal($result, 233, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 234, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 233, $target->home_team_id)['evidence']['offense']['selected_depth_rank'])->toBe(2);
    $starter->update(['depth_rank' => 2]);
    $backup->update(['depth_rank' => 1]);
    expect(matchupSignal($service->build($target), 184, $target->home_team_id)['status'])->toBe('not_matched');
    $backup->update(['depth_rank' => 2]);
    $starter->update(['depth_rank' => 1]);
    $chart->update(['observed_at' => now()->subDays(8)]);
    expect(matchupSignal($service->build($target), 184, $target->home_team_id)['status'])->toBe('insufficient_data');
    $chart->update(['observed_at' => now()->subHour()]);
    $target->away_team_id = $teams[0]->id;
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('matched');
    $starter->update(['depth_rank' => 2]);
    $backup->update(['depth_rank' => 1]);
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('not_matched');
    $starter->update(['depth_rank' => 1]);
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('insufficient_data');
    $backup->update(['depth_rank' => 2, 'source_updated_at' => now()->addDay()]);
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('insufficient_data');
    $backup->update(['source_updated_at' => null]);
    $chart->update(['observed_at' => now()->subDays(8)]);
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('insufficient_data');
    $chart->update(['observed_at' => now()->subHour()]);
    DB::table('nflverse_rosters')->insert([...$roster, 'nflverse_roster_key' => 'backup-conflict', 'gsis_id' => '00-9999']);
    expect(matchupSignal($service->build($target), 234, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('nflverse_roster_key', 'backup-conflict')->delete();
    $availability = Mockery::mock(QuarterbackAvailability::class);
    $availability->shouldReceive('forGame')->andReturn([]);
    $availability->shouldReceive('excludes')->andReturn(true);
    app()->instance(QuarterbackAvailability::class, $availability);
    $signal = matchupSignal($service->build($target), 234, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['reason'])->toContain('unavailable');
});

it('computes signed league-centered EPA differentials with explicit inclusive thresholds', function () {
    [$target, $teams] = matchupSignalLeague();
    $service = app(NflMatchupSignalService::class);
    $target->home_team_id = $teams[31]->id;
    $target->away_team_id = $teams[0]->id;
    $result = $service->build($target);
    foreach ([49, 50, 99, 139] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['epa_profile_differential']['value'])->toEqualWithDelta(.31, .000001)
            ->and($signal['evidence']['epa_profile_differential']['predictive_weight'])->toBe(0);
    }
    foreach ([100, 140] as $id) {
        $signal = matchupSignal($result, $id, $target->away_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['epa_profile_differential']['value'])->toEqualWithDelta(-.31, .000001);
    }
    $target->away_team_id = $teams[21]->id;
    $result = $service->build($target);
    expect(matchupSignal($result, 49, $target->home_team_id)['evidence']['epa_profile_differential']['value'])->toEqualWithDelta(.10, .000001)
        ->and(matchupSignal($result, 49, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 50, $target->home_team_id)['status'])->toBe('not_matched');
    $target->away_team_id = $teams[22]->id;
    expect(matchupSignal($service->build($target), 49, $target->home_team_id)['status'])->toBe('not_matched');
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[10]->id;
    expect(matchupSignal($service->build($target), 100, $target->home_team_id)['status'])->toBe('matched');
    $target->away_team_id = $teams[9]->id;
    expect(matchupSignal($service->build($target), 100, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[15]->id)->update(['epa' => null]);
    $result = $service->build($target);
    foreach ([49, 50, 99, 100, 139, 140] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('insufficient_data')
            ->and($signal['evidence']['epa_profile_differential']['value'])->toBeNull();
    }
});

it('keeps passing and rushing differentials separate and invariant to a league-wide EPA shift', function () {
    [$target, $teams] = matchupSignalLeague();
    $target->away_team_id = $teams[0]->id;
    $service = app(NflMatchupSignalService::class);
    DB::table('nflverse_pbp_plays')->where('play_type', 'run')->update(['epa' => DB::raw('-epa')]);
    $result = $service->build($target);
    expect(matchupSignal($result, 99, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 139, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 140, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 49, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->update(['epa' => DB::raw('epa + 2')]);
    $shifted = $service->build($target);
    foreach ([49, 50, 99, 100, 139, 140] as $id) {
        expect(matchupSignal($shifted, $id, $target->home_team_id)['evidence']['epa_profile_differential']['value'])
            ->toEqualWithDelta(matchupSignal($result, $id, $target->home_team_id)['evidence']['epa_profile_differential']['value'], .000001);
    }
    $target->away_team_id = $teams[11]->id;
    expect(matchupSignal($service->build($target), 140, $target->home_team_id)['status'])->toBe('matched');
    $target->away_team_id = $teams[12]->id;
    expect(matchupSignal($service->build($target), 140, $target->home_team_id)['status'])->toBe('not_matched');
});

it('compares expected-points performance against zero rather than ranks or league averages', function () {
    [$target, $teams] = matchupSignalLeague();
    $target->away_team_id = $teams[0]->id;
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    expect(matchupSignal($result, 46, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 47, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 47, $target->away_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 46, $target->away_team_id)['status'])->toBe('not_matched');
    $target->away_team_id = $teams[30]->id;
    $result = $service->build($target);
    foreach ([46, 47] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('not_matched');
    }
    $target->away_team_id = $teams[15]->id;
    $result = $service->build($target);
    expect(matchupSignal($result, 46, $target->home_team_id)['evidence']['defense']['value'])->toEqual(0)
        ->and(matchupSignal($result, 46, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->update(['epa' => DB::raw('epa + 1')]);
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[31]->id;
    $result = $service->build($target);
    $signal = matchupSignal($result, 46, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['rank'])->toBe(32)
        ->and($signal['evidence']['definition'])->toContain('zero baseline')
        ->and($result['predictive_weight'])->toBe(0);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[15]->id)->update(['epa' => null]);
    foreach ([46, 47] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

function matchupPositionLeague(): array
{
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        foreach (['QB', 'RB', 'TE', 'WR'] as $offset => $position) {
            DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'position-'.$i.'-'.$position,
                'season' => 2026, 'team_id' => $team->id, 'gsis_id' => '00-'.(10000 + $i * 4 + $offset), 'position' => $position]);
        }
        $plays = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->get();
        $group = intdiv($i, 8);
        foreach ($plays as $play) {
            $index = (int) substr($play->nflverse_play_key, strrpos($play->nflverse_play_key, '-') + 1);
            $fields = ['qb_scramble' => 0, 'yards_gained' => 0, 'air_yards' => 0];
            if ($index < 20) {
                $fields['passer_player_id'] = '00-'.(10000 + $i * 4);
                $fields['receiver_player_id'] = $index === 0 ? null : '00-'.(10000 + $i * 4 + ($index < 10 ? 2 : 3));
                $fields['air_yards'] = $index >= 10 && $index < 10 + $group * 3 ? 25 : 5;
                $fields['yards_gained'] = $index > 0 && $index <= $group * 5 ? 25 : 5;
            } else {
                $fields['rusher_player_id'] = '00-'.(10000 + $i * 4 + ($index < 25 ? 0 : 1));
                $fields['qb_scramble'] = $index < 22 + $group ? 1 : 0;
                $fields['yards_gained'] = ($index < 22 + $group || ($index >= 25 && $index < 25 + $group * 4)) ? 15 : 3;
            }
            DB::table('nflverse_pbp_plays')->where('id', $play->id)->update($fields);
        }
    }
    $target->home_qb_id = '00-10124';
    $target->away_team_id = $teams[1]->id;

    return [$target, $teams];
}

it('evaluates position-specific rushing receiving and selected-QB scramble matchups', function () {
    [$target, $teams] = matchupPositionLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([120, 134, 219, 245, 254] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
    $mobility = matchupSignal($result, 120, $target->home_team_id)['evidence']['offense'];
    expect($mobility['scrambles'])->toBe(15)->and($mobility['plays'])->toBe(75)
        ->and($mobility['value'])->toEqualWithDelta(15 / 75, .000001)
        ->and(matchupSignal($result, 134, $target->home_team_id)['evidence']['offense']['plays'])->toBe(45)
        ->and(matchupSignal($result, 245, $target->home_team_id)['evidence']['offense']['plays'])->toBe(27)
        ->and(matchupSignal($result, 254, $target->home_team_id)['evidence']['offense']['plays'])->toBe(30);
    $target->home_team_id = $teams[0]->id;
    expect(matchupSignal($service->build($target), 119, $target->home_team_id)['status'])->toBe('matched');
    $target->home_team_id = $teams[31]->id;
    $target->home_qb_id = '00-10000';
    expect(matchupSignal($service->build($target), 120, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(6 / 66, .000001);
    $target->home_qb_id = '00-10124';
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[31]->id)->where('play_type', 'run')->update(['qb_scramble' => null]);
    expect(matchupSignal($service->build($target), 120, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('rejects conflicting or wrong-season position mappings without multiplying play samples', function () {
    [$target, $teams] = matchupPositionLeague();
    $service = app(NflMatchupSignalService::class);
    $roster = ['nflverse_roster_key' => 'duplicate-position', 'season' => 2026,
        'team_id' => $teams[31]->id, 'gsis_id' => '00-10126', 'position' => 'TE'];
    DB::table('nflverse_rosters')->insert($roster);
    expect(matchupSignal($service->build($target), 245, $target->home_team_id)['evidence']['offense']['plays'])->toBe(27);
    DB::table('nflverse_rosters')->where('nflverse_roster_key', 'duplicate-position')->update(['position' => 'WR']);
    expect(matchupSignal($service->build($target), 245, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('nflverse_roster_key', 'duplicate-position')->delete();
    DB::table('nflverse_rosters')->where('gsis_id', '00-10126')->update(['season' => 2025]);
    expect(matchupSignal($service->build($target), 245, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('gsis_id', '00-10125')->update(['team_id' => $teams[0]->id]);
    expect(matchupSignal($service->build($target), 134, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('evaluates TE absence and multiple starting receiver absences against the correct defenses', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupPositionLeague();
    $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'position-injuries')]);
    foreach (['TE' => ['300'], 'WR' => ['301', '302']] as $position => $ids) {
        foreach ($ids as $id) {
            DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => $position.$id,
                'position_code' => $position, 'depth_rank' => 1, 'espn_athlete_id' => $id, 'observed_at' => now()->subHour()]);
        }
    }
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $chart->id,
            'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $injury = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'observed_at' => now(), 'payload_hash' => hash('sha256', 'position-injuries')]);
    foreach (['300', '301', '302'] as $id) {
        PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => $id,
            'injury_key' => $id, 'status' => 'Out', 'observed_at' => now()]);
    }
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 264, $target->home_team_id)['status'])->toBe('matched');
    $target->away_team_id = $teams[30]->id;
    expect(matchupSignal($service->build($target), 266, $target->home_team_id)['status'])->toBe('matched');
    $injury->entries()->where('espn_athlete_id', '302')->update(['status' => 'Questionable']);
    expect(matchupSignal($service->build($target), 266, $target->home_team_id)['status'])->toBe('insufficient_data');
    $injury->entries()->where('espn_athlete_id', '302')->update(['status' => 'Active']);
    expect(matchupSignal($service->build($target), 266, $target->home_team_id)['status'])->toBe('not_matched');
    DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'TE2',
        'position_code' => 'TE', 'depth_rank' => 1, 'espn_athlete_id' => '303', 'observed_at' => now()->subHour()]);
    expect(matchupSignal($service->build($target), 264, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('combines defensive injury evidence with the correct offensive target and efficiency metrics', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupPositionLeague();
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['air_yards' => 25]);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->whereNotNull('receiver_player_id')->update(['receiver_player_id' => '00-10127']);
    $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->away_team_id,
        'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'defensive-injuries')]);
    foreach (['CB' => '400', 'FS' => '401', 'SS' => '402', 'MLB' => '403'] as $position => $id) {
        DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => $position,
            'position_code' => $position, 'depth_rank' => 1, 'espn_athlete_id' => $id, 'observed_at' => now()->subHour()]);
    }
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['away' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->away_team_id, 'side' => 'away', 'snapshot_id' => $chart->id,
            'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $injury = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->away_team_id,
        'espn_team_id' => '123', 'observed_at' => now(), 'payload_hash' => hash('sha256', 'defensive-injuries')]);
    foreach (['400', '401', '402', '403'] as $id) {
        PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => $id,
            'injury_key' => $id, 'status' => 'Out', 'observed_at' => now()]);
    }
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([256, 267, 270] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
    $injury->entries()->where('espn_athlete_id', '402')->update(['status' => 'Questionable']);
    expect(matchupSignal($service->build($target), 267, $target->home_team_id)['status'])->toBe('matched');
    $injury->entries()->where('espn_athlete_id', '401')->update(['status' => 'Active']);
    expect(matchupSignal($service->build($target), 267, $target->home_team_id)['status'])->toBe('insufficient_data');
    $injury->entries()->where('espn_athlete_id', '402')->update(['status' => 'Active']);
    expect(matchupSignal($service->build($target), 267, $target->home_team_id)['status'])->toBe('not_matched');
    expect(matchupSignal($result, 256, $target->home_team_id)['evidence']['offense']['value'])->toEqual(1)
        ->and(matchupSignal($result, 256, $target->home_team_id)['evidence']['offense']['leading_receiver_ids'])->toBe(['00-10127']);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->whereNotNull('receiver_player_id')->update(['receiver_player_id' => '00-10126']);
    expect(matchupSignal($service->build($target), 272, $target->home_team_id)['status'])->toBe('matched');
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->whereNotNull('receiver_player_id')->update(['receiver_player_id' => '00-10125']);
    expect(matchupSignal($service->build($target), 273, $target->home_team_id)['status'])->toBe('matched');
    $injury->entries()->where('espn_athlete_id', '403')->update(['status' => 'Questionable']);
    expect(matchupSignal($service->build($target), 273, $target->home_team_id)['status'])->toBe('insufficient_data');
    $injury->entries()->where('espn_athlete_id', '403')->update(['status' => 'Active']);
    expect(matchupSignal($service->build($target), 273, $target->home_team_id)['status'])->toBe('not_matched');
    DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'CB2',
        'position_code' => 'CB', 'depth_rank' => 1, 'espn_athlete_id' => '404', 'observed_at' => now()->subHour()]);
    expect(matchupSignal($service->build($target), 256, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('requires two timely QB chart observations before reporting a recent projected change', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    $target->away_team_id = $teams[30]->id;
    $target->home_qb_id = '00-9000';
    $charts = [];
    foreach (['300', '301'] as $i => $id) {
        $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
            'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subDays(2 - $i), 'payload_hash' => hash('sha256', 'change-'.$i)]);
        DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => 'QB', 'position_code' => 'QB',
            'depth_rank' => 1, 'espn_athlete_id' => $id, 'observed_at' => $chart->observed_at]);
        $charts[] = $chart;
    }
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $charts[1]->id,
            'snapshot_uuid' => $charts[1]->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'changed-qb', 'season' => 2026,
        'team_id' => $target->home_team_id, 'gsis_id' => '00-9000', 'espn_id' => '301', 'position' => 'QB']);
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 236, $target->home_team_id)['status'])->toBe('matched');
    $charts[0]->entries()->update(['espn_athlete_id' => '301']);
    expect(matchupSignal($service->build($target), 236, $target->home_team_id)['status'])->toBe('not_matched');
    $charts[0]->update(['observed_at' => now()->subDays(8)]);
    expect(matchupSignal($service->build($target), 236, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('compares the selected quarterbacks charted checkdown share with short-pass defense', function () {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $index => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['passer_player_id' => '00-'.(1000 + $index), 'air_yards' => 5, 'ftn_read_thrown' => 'DES']);
    }
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[0]->id;
    $home = DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031');
    (clone $home)->update(['ftn_read_thrown' => 'CHK']);
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 224, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(1, .000001)
        ->and($signal['evidence']['offense']['plays'])->toBe(57)
        ->and($signal['evidence']['offense']['checkdowns'])->toBe(57)
        ->and($signal['evidence']['offense']['league_players'])->toBe(32)
        ->and($signal['evidence']['defense_metric'])->toBe('short_pass_epa');

    foreach (['0', '1', '2', 'DES', 'SD'] as $read) {
        (clone $home)->update(['ftn_read_thrown' => $read]);
        $signal = matchupSignal($service->build($target), 224, $target->home_team_id);
        expect($signal['status'])->toBe('not_matched')->and($signal['evidence']['offense']['value'])->toEqualWithDelta(0, .000001);
    }
    (clone $home)->update(['ftn_read_thrown' => null]);
    expect(matchupSignal($service->build($target), 224, $target->home_team_id)['status'])->toBe('insufficient_data');
    (clone $home)->update(['ftn_read_thrown' => 'CHK']);
    $target->home_qb_id = '00-1000';
    expect(matchupSignal($service->build($target), 224, $target->home_team_id)['status'])->toBe('not_matched');
    $target->home_qb_id = null;
    expect(matchupSignal($service->build($target), 224, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('requires read coverage in every QB appearance and a qualified ranking population', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    foreach ($teams as $index => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['passer_player_id' => '00-'.(1000 + $index), 'air_yards' => 5, 'ftn_read_thrown' => 'CHK']);
    }
    $target->home_qb_id = '00-1031';
    $service = app(NflMatchupSignalService::class);
    $ids = DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[0]->id)->where('passer_player_id', '00-1031')->where('is_sack', false)->limit(2)->pluck('id');
    DB::table('nflverse_pbp_plays')->whereIn('id', $ids)->update(['ftn_read_thrown' => null]);
    expect(matchupSignal($service->build($target), 224, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->whereIn('id', $ids)->update(['ftn_read_thrown' => 'CHK']);
    DB::table('nflverse_pbp_plays')->whereIn('possession_team_id', $teams->take(9)->pluck('id'))->update(['ftn_read_thrown' => null]);
    $signal = matchupSignal($service->build($target), 224, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['reason'])->toContain('24 qualified');
});

it('counts explicit false starts including penalty no-plays only for the road offense', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['is_penalty' => false]);
    $target->away_team_id = $teams[0]->id;
    $penalties = [];
    foreach ($games as $game) {
        if ((int) $game->home_team_id !== (int) $teams[0]->id) {
            continue;
        }
        for ($i = 0; $i < 4; $i++) {
            $penalties[] = ['nflverse_play_key' => 'false-start-'.$game->id.'-'.$i, 'nfl_game_id' => $game->id,
                'possession_team_id' => $teams[0]->id, 'defense_team_id' => $teams[31]->id,
                'play_type' => 'no_play', 'description' => 'False Start. No Play.',
                'is_penalty' => true, 'penalty_type' => 'False Start', 'penalty_team_id' => $teams[0]->id];
        }
    }
    DB::table('nflverse_pbp_plays')->insert($penalties);
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 182, $teams[0]->id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(12 / 132, .000001)
        ->and($signal['evidence']['league_teams'])->toBe(32)
        ->and($signal['evidence']['offense_only'])->toBeTrue();

    DB::table('nflverse_pbp_plays')->where('nflverse_play_key', $penalties[0]['nflverse_play_key'])->update(['penalty_team_id' => $teams[31]->id, 'penalty_type' => 'Defensive Offside']);
    $signal = matchupSignal($service->build($target), 182, $teams[0]->id);
    expect($signal['evidence']['offense']['value'])->toEqualWithDelta(11 / 132, .000001);
    $target->neutral_site = true;
    expect(matchupSignal($service->build($target), 182, $teams[0]->id)['status'])->toBe('not_matched');
    $target->neutral_site = false;
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[31]->id;
    expect(matchupSignal($service->build($target), 182, $teams[0]->id)['status'])->toBe('not_matched');

    DB::table('nflverse_pbp_plays')->where('nflverse_play_key', $penalties[1]['nflverse_play_key'])->update(['penalty_type' => null]);
    $signal = matchupSignal($service->build($target), 182, $teams[0]->id);
    expect($signal['evidence']['offense']['value'])->toEqualWithDelta(10 / 131, .000001);
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[0]->id)->where('possession_team_id', $teams[0]->id)->where('play_type', 'pass')->update(['is_penalty' => null]);
    expect(matchupSignal($service->build($target), 182, $teams[0]->id)['status'])->toBe('insufficient_data');
});

it('does not infer false starts from penalty text or missing classification', function () {
    [$target, $teams] = matchupSignalLeague();
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 182, $target->away_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->update(['is_penalty' => false, 'description' => 'False Start']);
    $signal = matchupSignal($service->build($target), 182, $target->away_team_id);
    expect($signal['status'])->toBe('not_matched')->and($signal['evidence']['offense']['value'])->toEqual(0);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[0]->id)->update(['is_penalty' => null]);
    $signal = matchupSignal($service->build($target), 182, $target->away_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['reason'])->toContain('all 32');
});

it('attributes quarterback interceptions and lost fumbles without charging receiver fumbles to the passer', function () {
    [$target, $teams] = matchupPositionLeague();
    DB::table('nflverse_pbp_plays')->update(['is_interception' => 0, 'is_fumble_lost' => 0]);
    foreach ($teams as $i => $team) {
        $limit = $i < 8 ? 0 : ($i < 24 ? 1 : 3);
        foreach (range(1, max(1, $limit)) as $play) {
            if ($limit > 0) {
                DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('nflverse_play_key', 'like', '%-'.$i.'-'.$play)->update(['is_interception' => 1]);
            }
        }
    }
    $home = DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id);
    foreach ([0, 20] as $play) {
        (clone $home)->where('nflverse_play_key', 'like', '%-31-'.$play)->update(['is_fumble_lost' => 1, 'fumbled_1_player_id' => '00-10124']);
    }
    (clone $home)->where('nflverse_play_key', 'like', '%-31-4')->update(['is_fumble_lost' => 1, 'fumbled_1_player_id' => '00-10127']);
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 227, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['turnovers'])->toBe(15)
        ->and($signal['evidence']['offense']['plays'])->toBe(75)
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.2, .000001)
        ->and($signal['evidence']['defense']['value'])->toEqualWithDelta(3 / 40, .000001)
        ->and($signal['evidence']['offense']['league_players'])->toBe(32);

    (clone $home)->where('nflverse_play_key', 'like', '%-31-0')->update(['fumbled_2_player_id' => '00-99999']);
    expect(matchupSignal($service->build($target), 227, $target->home_team_id)['status'])->toBe('insufficient_data');
    (clone $home)->update(['fumbled_2_player_id' => null]);
    (clone $home)->where('nflverse_play_key', 'like', '%-31-20')->update(['fumbled_1_player_id' => null]);
    expect(matchupSignal($service->build($target), 227, $target->home_team_id)['status'])->toBe('insufficient_data');
    (clone $home)->update(['is_interception' => 0, 'is_fumble_lost' => 0]);
    $signal = matchupSignal($service->build($target), 228, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['offense']['turnovers'])->toBe(0);
    $target->home_qb_id = null;
    expect(matchupSignal($service->build($target), 228, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('holds turnover rankings with missing flags unidentified runs or too few qualified quarterbacks', function () {
    [$target, $teams] = matchupPositionLeague();
    DB::table('nflverse_pbp_plays')->update(['is_interception' => 0, 'is_fumble_lost' => 0]);
    $service = app(NflMatchupSignalService::class);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')->update(['is_interception' => null]);
    expect(matchupSignal($service->build($target), 227, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->update(['is_interception' => 0]);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->where('play_type', 'run')->update(['rusher_player_id' => null]);
    expect(matchupSignal($service->build($target), 227, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->whereIn('possession_team_id', $teams->take(9)->pluck('id'))->update(['is_fumble_lost' => null]);
    $signal = matchupSignal($service->build($target), 228, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['reason'])->toContain('24 qualified');
});

it('compares target-weighted WR size with a complete game-linked projected defensive group', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupPositionLeague();
    $charts = [];
    foreach ($teams as $i => $team) {
        $tier = intdiv($i, 8);
        DB::table('nflverse_rosters')->where('team_id', $team->id)->where('position', 'WR')->update(['height' => 70 + $tier * 2, 'weight' => 180 + $tier * 10]);
        $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id,
            'espn_team_id' => (string) $team->id, 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'size-'.$i)]);
        $charts[$team->id] = $chart;
        foreach (['CB', 'CB', 'FS', 'SS'] as $slot => $position) {
            $espn = (string) (70000 + $i * 4 + $slot);
            $chart->entries()->create(['position_slot_key' => $position.$slot, 'position_code' => $position, 'depth_rank' => 1,
                'espn_athlete_id' => $espn, 'observed_at' => now()->subHour()]);
            DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'size-'.$espn, 'season' => 2026, 'team_id' => $team->id,
                'gsis_id' => '00-'.$espn, 'espn_id' => $espn, 'position' => 'DB', 'height' => 68 + $tier * 2, 'weight' => 175 + $tier * 10]);
        }
    }
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    $links = [];
    foreach (['home', 'away'] as $side) {
        $chart = $charts[$target->{$side.'_team_id'}];
        $links[$side] = ['depth_chart_game_link' => ['game_id' => $target->id, 'team_id' => $chart->team_id, 'side' => $side,
            'snapshot_id' => $chart->id, 'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String()]];
    }
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', ['model_metadata' => ['quarterback' => $links]]);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([248 => [210, 175], 249 => [76, 68]] as $id => [$offense, $defense]) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('matched')->and($signal['evidence']['league_teams'])->toBe(32)
            ->and($signal['evidence']['offense']['value'])->toEqualWithDelta($offense, .000001)
            ->and($signal['evidence']['defense']['value'])->toEqualWithDelta($defense, .000001)
            ->and($signal['evidence']['defense']['depth_chart_link_id'])->not->toBeNull();
    }
    $twoWay = DB::table('nflverse_rosters')->where('team_id', $target->away_team_id)->where('position', 'DB')->first();
    DB::table('nflverse_rosters')->where('id', $twoWay->id)->update(['position' => 'WR']);
    foreach ([248, 249] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('matched');
    }
    DB::table('nflverse_rosters')->where('id', $twoWay->id)->update(['team_id' => $teams[2]->id]);
    $signal = matchupSignal($service->build($target), 248, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['defense']['cross_team_measurement_player_ids'])->toBe([(string) $twoWay->espn_id]);
    $conflict = (array) $twoWay;
    unset($conflict['id']);
    $conflict['nflverse_roster_key'] .= '-ambiguous';
    $conflict['team_id'] = $teams[3]->id;
    $conflict['gsis_id'] = '00-different-player';
    DB::table('nflverse_rosters')->insert($conflict);
    expect(matchupSignal($service->build($target), 248, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('nflverse_roster_key', $conflict['nflverse_roster_key'])->delete();
    DB::table('nflverse_rosters')->where('id', $twoWay->id)->update(['team_id' => $target->away_team_id, 'position' => 'DB']);
    $row = (array) DB::table('nflverse_rosters')->where('team_id', $target->away_team_id)->where('position', 'DB')->first();
    unset($row['id']);
    $row['nflverse_roster_key'] .= '-conflict';
    $row['height'] = null;
    DB::table('nflverse_rosters')->insert($row);
    expect(matchupSignal($service->build($target), 249, $target->home_team_id)['status'])->toBe('insufficient_data');
    expect(matchupSignal($service->build($target), 248, $target->home_team_id)['status'])->toBe('matched');
    DB::table('nflverse_rosters')->where('nflverse_roster_key', $row['nflverse_roster_key'])->delete();
    $charts[$target->away_team_id]->update(['observed_at' => now()->subDays(8)]);
    foreach ([248, 249] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
    $charts[$target->away_team_id]->update(['observed_at' => now()->subHour()]);
    DB::table('nflverse_rosters')->where('team_id', $target->home_team_id)->where('position', 'WR')->update(['height' => 91]);
    expect(matchupSignal($service->build($target), 249, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_rosters')->where('team_id', $target->home_team_id)->where('position', 'WR')->update(['height' => 76]);
    $charts[$target->away_team_id]->entries()->where('position_code', 'CB')->first()->update(['espn_athlete_id' => null]);
    foreach ([248, 249] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('requires game-linked and fresh defensive size evidence rather than only a current roster', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target] = matchupPositionLeague();
    DB::table('nflverse_rosters')->where('position', 'WR')->update(['height' => 74, 'weight' => 200]);
    foreach ([248, 249] as $id) {
        expect(matchupSignal(app(NflMatchupSignalService::class)->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('evaluates paired quarterback coverage rules with actual usage and strict missing-data gates', function (int $rule, string $field, string $code, string $other) {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        $passes = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass');
        $passes->update(['passer_player_id' => '00-'.(1000 + $i), $field => $other]);
        foreach ((clone $passes)->get()->groupBy('nfl_game_id') as $rows) {
            DB::table('nflverse_pbp_plays')->whereIn('id', $rows->take(5 + intdiv($i, 8) * 5)->pluck('id'))->update([$field => $code]);
        }
    }
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[1]->id;
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    $signal = matchupSignal($result, $rule, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and($signal['evidence']['offense']['plays'])->toBe(60)
        ->and($signal['evidence']['offense']['league_players'])->toBe(32)
        ->and($signal['evidence']['defense']['value'])->toBe(1.0)
        ->and(matchupSignal($result, $rule + 1, $target->home_team_id)['status'])->toBe('not_matched');
    $target->home_qb_id = '00-1000';
    expect(matchupSignal($service->build($target), $rule + 1, $target->home_team_id)['status'])->toBe('matched');
    $target->home_qb_id = '00-1031';
    DB::table('nflverse_pbp_plays')->where('passer_player_id', '00-1031')->update([$field => null]);
    expect(matchupSignal($service->build($target), $rule, $target->home_team_id)['status'])->toBe('insufficient_data');
    $target->home_qb_id = null;
    expect(matchupSignal($service->build($target), $rule, $target->home_team_id)['status'])->toBe('insufficient_data');
})->with([
    [201, 'participation_man_zone', 'MAN_COVERAGE', 'ZONE_COVERAGE'],
    [203, 'participation_man_zone', 'ZONE_COVERAGE', 'MAN_COVERAGE'],
    [205, 'participation_coverage', 'COVER_1', 'COVER_3'],
    [207, 'participation_coverage', 'COVER_2', '2_MAN'],
    [209, 'participation_coverage', 'COVER_3', 'COVER_1'],
    [211, 'participation_coverage', 'COVER_4', 'COVER_6'],
]);

it('keeps historical coverage separate from current-season evidence and excludes Cover 2 man', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    foreach ($games as $game) {
        $game->update(['season' => 2025, 'game_date' => $game->game_date->subYear()]);
    }
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['passer_player_id' => '00-'.(1000 + $i), 'participation_man_zone' => 'MAN_COVERAGE', 'participation_coverage' => '2_MAN']);
    }
    $target->home_qb_id = '00-1031';
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 201, $target->home_team_id)['status'])->toBe('insufficient_data');
    $prior = $service->build($target, 'previous_season');
    expect(matchupSignal($prior, 201, $target->home_team_id)['evidence']['offense']['plays'])->toBe(60)
        ->and(matchupSignal($prior, 207, $target->home_team_id)['status'])->toBe('insufficient_data')
        ->and(matchupSignal($prior, 207, $target->home_team_id)['evidence']['offense']['plays'])->toBe(0);
});

function pressureMatchupLeague(): array
{
    [$target, $teams, $games] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        $passes = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass');
        $passes->update(['passer_player_id' => '00-'.(1000 + $i), 'ftn_n_blitzers' => $i >= 24 ? 1 : 0]);
        foreach ((clone $passes)->get()->groupBy('nfl_game_id') as $gameId => $rows) {
            $pressures = 5 + intdiv($i, 8) * 4;
            DB::table('nfl_matchup_pressure_samples')->insert(['game_id' => $gameId, 'team_id' => $team->id,
                'opponent_id' => $rows->first()->defense_team_id, 'season' => 2026, 'gsis_id' => '00-'.(1000 + $i),
                'pfr_id' => 'Test'.$i, 'pressures' => $pressures, 'sacks' => 1, 'pressure_rate' => $pressures / 20, 'observed_at' => now()]);
        }
    }
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[1]->id;

    return [$target, $teams, $games];
}

it('uses observed pressure rates and selected-QB sack conversion with tie-safe ranks', function () {
    [$target, $teams] = pressureMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([150, 177, 199] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
    foreach ([149, 153, 178, 200] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('not_matched');
    }
    expect(matchupSignal($result, 150, $target->home_team_id)['evidence']['offense']['value'])->toBe(.85)
        ->and(matchupSignal($result, 199, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(1 / 17, .00001);
    $target->home_team_id = $teams[0]->id;
    $target->home_qb_id = '00-1000';
    $result = $service->build($target);
    foreach ([149, 153, 178, 200] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
});

it('withholds pressure comparisons for mismatched sacks or missing backup samples', function (string $gap) {
    [$target] = pressureMatchupLeague();
    $row = DB::table('nfl_matchup_pressure_samples')->where('gsis_id', '00-1031')->first();
    if ($gap === 'backup') {
        DB::table('nflverse_pbp_plays')->where('nfl_game_id', $row->game_id)->where('passer_player_id', '00-1031')->where('is_sack', false)->limit(1)->update(['passer_player_id' => '00-99999']);
    } else {
        DB::table('nfl_matchup_pressure_samples')->where('id', $row->id)->update([$gap => $gap === 'sacks' ? 2 : .5]);
    }
    $result = app(NflMatchupSignalService::class)->build($target);
    foreach ([149, 150, 153, 199, 200] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
})->with(['sacks', 'backup']);

it('normalizes pressure counts to one play-by-play denominator rather than averaging rounded provider percentages', function () {
    [$target] = pressureMatchupLeague();
    DB::table('nfl_matchup_pressure_samples')->where('gsis_id', '00-1031')->update(['pressure_rate' => .81]);
    $signal = matchupSignal(app(NflMatchupSignalService::class)->build($target), 150, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['offense']['value'])->toBe(.85)
        ->and($signal['evidence']['offense']['dropbacks'])->toBe(60)->and($signal['evidence']['offense']['pressures'])->toBe(51);
});

it('holds team pressure rankings when a provider passer is absent from play history', function () {
    [$target] = pressureMatchupLeague();
    $sample = (array) DB::table('nfl_matchup_pressure_samples')->where('team_id', $target->home_team_id)->first();
    unset($sample['id']);
    DB::table('nfl_matchup_pressure_samples')->insert([...$sample, 'gsis_id' => '00-99999', 'pfr_id' => 'MissingQB']);
    expect(matchupSignal(app(NflMatchupSignalService::class)->build($target), 150, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('distinguishes exact one two and three-plus unavailable projected linemen against measured pressure', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target] = pressureMatchupLeague();
    $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'season' => 2026, 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'line-injuries')]);
    $injury = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $target->home_team_id,
        'espn_team_id' => '123', 'observed_at' => now(), 'payload_hash' => hash('sha256', 'line-injuries')]);
    foreach (['LT', 'LG', 'C', 'RG', 'RT'] as $index => $position) {
        DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => $position,
            'position_code' => $position, 'depth_rank' => 1, 'espn_athlete_id' => (string) (500 + $index), 'observed_at' => now()->subHour()]);
        PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => (string) (500 + $index),
            'injury_key' => $position, 'status' => 'Active', 'observed_at' => now()]);
    }
    app(PredictionFeatureSnapshotRecorder::class)->record(Prediction::factory()->create(['game_id' => $target->id]), $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $target->home_team_id, 'side' => 'home', 'snapshot_id' => $chart->id,
            'snapshot_uuid' => $chart->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $service = app(NflMatchupSignalService::class);
    for ($count = 0; $count <= 5; $count++) {
        if ($count) {
            $injury->entries()->where('espn_athlete_id', (string) (499 + $count))->update(['status' => 'Out']);
        }
        $result = $service->build($target);
        foreach ([154 => $count === 1, 155 => $count === 2, 156 => $count >= 3] as $id => $matched) {
            $signal = matchupSignal($result, $id, $target->home_team_id);
            expect($signal['status'])->toBe($matched ? 'matched' : 'not_matched')
                ->and($signal['evidence']['offense']['value'])->toBe($count);
        }
    }
    $injury->entries()->where('espn_athlete_id', '500')->update(['status' => 'Questionable']);
    expect(matchupSignal($service->build($target), 156, $target->home_team_id)['status'])->toBe('insufficient_data');
    $injury->entries()->where('espn_athlete_id', '500')->update(['status' => 'Out']);
    $chart->entries()->where('position_code', 'LT')->update(['espn_athlete_id' => '501']);
    expect(matchupSignal($service->build($target), 156, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('uses exactly four charted rushers independently of blitz flags and requires complete league coverage', function () {
    [$target, $teams] = pressureMatchupLeague();
    $target->home_team_id = $teams[0]->id;
    foreach ($teams as $i => $team) {
        DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass')
            ->update(['ftn_n_pass_rushers' => $i >= 24 ? 4 : 3, 'ftn_n_blitzers' => 1]);
    }
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 179, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['defense']['value'])->toBe(1.0);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[31]->id)->update(['ftn_n_pass_rushers' => null]);
    expect(matchupSignal($service->build($target), 179, $target->home_team_id)['status'])->toBe('insufficient_data');
});

function trackingMatchupLeague(): array
{
    [$target, $teams] = pressureMatchupLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'yac-'.$i, 'season' => 2026, 'team_id' => $team->id, 'gsis_id' => 'wr-'.$i, 'position' => 'WR']);
        $passes = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass');
        $passes->update(['complete_pass' => 1, 'receiver_player_id' => 'wr-'.$i, 'yards_after_catch' => $i]);
        foreach ((clone $passes)->get()->groupBy('nfl_game_id') as $gameId => $rows) {
            DB::table('nfl_matchup_tracking_samples')->insert([
                ['game_id' => $gameId, 'team_id' => $team->id, 'season' => 2026, 'metric' => 'release_time',
                    'player_id' => 'gsis:00-'.(1000 + $i), 'value' => 4 - $i / 16, 'sample_size' => 19, 'observed_at' => now()],
                ['game_id' => $gameId, 'team_id' => $team->id, 'season' => 2026, 'metric' => 'missed_tackles',
                    'player_id' => 'pfr:Defender'.$i, 'value' => 31 - $i, 'sample_size' => 80, 'observed_at' => now()],
            ]);
        }
    }

    return [$target, $teams];
}

it('evaluates measured release times and receiver YAC against complete team tackling samples', function () {
    [$target, $teams] = trackingMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([85, 255, 222] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
    expect(matchupSignal($result, 223, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 85, $target->home_team_id)['evidence']['offense']['plays'])->toBe(57)
        ->and(matchupSignal($result, 222, $target->home_team_id)['evidence']['offense']['value'])->toBe(2.0625);
    $target->home_qb_id = '00-1000';
    expect(matchupSignal($service->build($target), 223, $target->home_team_id)['status'])->toBe('matched');
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[31]->id;
    expect(matchupSignal($service->build($target), 86, $target->home_team_id)['status'])->toBe('matched');
});

it('does not use incomplete tracking attempts or missing tackle counts as evidence', function () {
    [$target] = trackingMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    DB::table('nfl_matchup_tracking_samples')->where('player_id', 'gsis:00-1031')->limit(1)->update(['sample_size' => 10]);
    expect(matchupSignal($service->build($target), 222, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nfl_matchup_tracking_samples')->where('metric', 'missed_tackles')->where('team_id', $target->away_team_id)->limit(1)->update(['value' => null]);
    foreach ([85, 255] as $id) {
        expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('excludes incompletions and sacks from YAC and keeps missing YAC unknown', function () {
    [$target] = trackingMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    DB::table('nflverse_pbp_plays')->where('is_sack', true)->update(['yards_after_catch' => 999]);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')->where('is_sack', false)->limit(1)->update(['complete_pass' => 0, 'yards_after_catch' => 999]);
    expect(matchupSignal($service->build($target), 85, $target->home_team_id)['evidence']['offense']['value'])->toBe(31.0);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')->update(['yards_after_catch' => null]);
    expect(matchupSignal($service->build($target), 85, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('weights release time by weekly source attempts and rejects an unmatched source appearance', function () {
    [$target] = trackingMatchupLeague();
    $rows = DB::table('nfl_matchup_tracking_samples')->where('player_id', 'gsis:00-1031')->orderBy('game_id')->get();
    foreach ([[2, 19], [4, 20], [6, 18]] as $index => [$seconds, $attempts]) {
        DB::table('nfl_matchup_tracking_samples')->where('id', $rows[$index]->id)->update(['value' => $seconds, 'sample_size' => $attempts]);
    }
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 222, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(226 / 57, .000001);
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $rows[0]->game_id)->where('passer_player_id', '00-1031')->update(['passer_player_id' => '00-99999']);
    expect(matchupSignal($service->build($target), 222, $target->home_team_id)['evidence']['offense']['eligible'])->toBeFalse();
});

it('uses the last complete week while a new final game awaits plays and advances when imported', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    $new = Game::factory()->create([
        'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[31]->id,
        'season' => 2026, 'season_type' => '2', 'week' => 4, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-19', 'game_time' => '17:00:00',
    ]);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    expect($result['baseline'])->toMatchArray(['mode' => 'last_complete_week', 'through_week' => 3, 'through_date' => '2026-09-03', 'games' => 48])
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['league_teams'])->toBe(32)
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['games'])->toBe(3)
        ->and($service->build($target, 'previous_season')['baseline'])->toBeNull();
    $plays = DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[0]->id)->get();
    foreach ($plays as $play) {
        $row = (array) $play;
        unset($row['id']);
        $row['nfl_game_id'] = $new->id;
        $row['nflverse_play_key'] .= '-new-week';
        DB::table('nflverse_pbp_plays')->insert($row);
    }
    $result = $service->build($target);
    expect($result['baseline'])->toBeNull()
        ->and(matchupSignal($result, 1, $target->home_team_id)['evidence']['offense']['games'])->toBe(4);
});

it('excludes the whole new week and does not call a nonfinal earlier week complete', function () {
    [$target, $teams, $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $games[47]->id)->delete();
    $games[16]->update(['status' => 'STATUS_SCHEDULED']);
    $result = app(NflMatchupSignalService::class)->build($target);
    expect($result['baseline']['through_week'])->toBe(1)
        ->and($result['baseline']['games'])->toBe(16)
        ->and(matchupSignal($result, 1, $target->home_team_id)['status'])->toBe('insufficient_data');
});

function contactMatchupLeague(): array
{
    [$target, $teams] = trackingMatchupLeague();
    foreach ($teams as $i => $team) {
        $ids = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->distinct()->pluck('nfl_game_id');
        foreach ($ids as $gameId) {
            foreach (['rush_ybc', 'rush_yac'] as $metric) {
                DB::table('nfl_matchup_tracking_samples')->insert(['game_id' => $gameId, 'team_id' => $team->id,
                    'season' => 2026, 'metric' => $metric, 'player_id' => 'pfr:Rusher'.$i,
                    'value' => ($i - 2) * 2, 'sample_size' => 20, 'observed_at' => now()]);
            }
        }
    }

    return [$target, $teams];
}

it('ranks pooled contact yards and opponent contact yards with negative yardage intact', function () {
    [$target, $teams] = contactMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([107, 109] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['offense']['value'])->toBe(2.9)
            ->and($signal['evidence']['offense']['plays'])->toBe(60)
            ->and($signal['evidence']['league_teams'])->toBe(32);
    }
    expect(matchupSignal($result, 107, $target->home_team_id)['evidence']['defense']['value'])->toBe(2.8);
    $target->home_team_id = $teams[0]->id;
    $target->away_team_id = $teams[31]->id;
    $signal = matchupSignal($service->build($target), 108, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['offense']['value'])->toBe(-.2);
});

it('holds contact-yard rankings for missing measurements mismatched carries or incomplete games', function (string $failure) {
    [$target, $teams] = contactMatchupLeague();
    $query = DB::table('nfl_matchup_tracking_samples')->where('team_id', $teams[0]->id)->where('metric', 'rush_ybc')->limit(1);
    match ($failure) {
        'missing' => $query->update(['value' => null]),
        'carries' => $query->update(['sample_size' => 10]),
        'absent' => $query->delete(),
    };
    $signal = matchupSignal(app(NflMatchupSignalService::class)->build($target), 107, $target->home_team_id);
    expect($signal['status'])->toBe('insufficient_data')->and($signal['evidence']['league_teams'])->toBe(31);
})->with(['missing', 'carries', 'absent']);

it('weights contact yards by carries instead of averaging weekly rates and rejects rank-boundary ties', function () {
    [$target] = contactMatchupLeague();
    $rows = DB::table('nfl_matchup_tracking_samples')->where('team_id', $target->home_team_id)->where('metric', 'rush_ybc')->orderBy('game_id')->get();
    DB::table('nfl_matchup_tracking_samples')->where('id', $rows[0]->id)->update(['value' => 20, 'sample_size' => 10]);
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $rows[0]->game_id)->where('possession_team_id', $target->home_team_id)->where('play_type', 'run')->limit(10)->delete();
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 107, $target->home_team_id);
    expect($signal['evidence']['offense']['value'])->toEqualWithDelta(136 / 50, .000001);
    DB::table('nfl_matchup_tracking_samples')->where('metric', 'rush_ybc')->update(['value' => DB::raw('sample_size * 2')]);
    expect(matchupSignal($service->build($target), 107, $target->home_team_id)['status'])->toBe('not_matched');
});

it('compares the selected QB scramble rate with actual man usage without assuming man coverage caused scrambles', function () {
    [$target, $teams] = matchupPositionLeague();
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->update(['participation_man_zone' => 'ZONE_COVERAGE']);
    DB::table('nflverse_pbp_plays')->where('play_type', 'pass')->whereIn('defense_team_id', $teams->take(8)->pluck('id'))
        ->update(['participation_man_zone' => 'MAN_COVERAGE']);
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), 218, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['offense']['value'])->toEqualWithDelta(.2, .000001)
        ->and($signal['evidence']['defense']['value'])->toBe(1.0);
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->update(['participation_man_zone' => null]);
    expect(matchupSignal($service->build($target), 218, $target->home_team_id)['status'])->toBe('insufficient_data');
});

function participationMatchupLeague(): array
{
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'zone-'.$i, 'season' => 2026,
            'team_id' => $team->id, 'gsis_id' => 'wr-'.$i, 'position' => 'WR']);
        $passes = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id)->where('play_type', 'pass');
        $passes->update(['passer_player_id' => '00-'.(1000 + $i), 'receiver_player_id' => 'wr-'.$i,
            'participation_pressure' => false, 'participation_rushers' => 4, 'participation_man_zone' => 'ZONE_COVERAGE']);
        foreach ((clone $passes)->get()->groupBy('nfl_game_id') as $rows) {
            DB::table('nflverse_pbp_plays')->whereIn('id', $rows->take(5 + intdiv($i, 8) * 5)->pluck('id'))
                ->update(['participation_pressure' => true]);
        }
    }
    $target->home_qb_id = '00-1031';
    $target->away_team_id = $teams[1]->id;

    return [$target, $teams];
}

it('uses actual pressured passes for QB EPA and pressure generated by four rushers', function () {
    [$target, $teams] = participationMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([176, 197, 253] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('matched');
    }
    expect(matchupSignal($result, 197, $target->home_team_id)['evidence']['offense']['value'])->toEqualWithDelta(.15, .000001)
        ->and(matchupSignal($result, 176, $target->home_team_id)['evidence']['defense']['value'])->toBe(1.0);
    $target->home_qb_id = '00-1000';
    expect(matchupSignal($service->build($target), 198, $target->home_team_id)['status'])->toBe('matched');
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->where('play_type', 'pass')
        ->update(['participation_pressure' => false]);
    expect(matchupSignal($service->build($target), 176, $target->home_team_id)['status'])->toBe('not_matched');
});

it('holds pressure and receiver-zone comparisons for incomplete required charting', function (int $id, string $field) {
    [$target] = participationMatchupLeague();
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->update([$field => null]);
    expect(matchupSignal(app(NflMatchupSignalService::class)->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
})->with([[176, 'participation_rushers'], [197, 'participation_pressure'], [198, 'participation_pressure'], [253, 'participation_man_zone']]);

it('compares personnel usage with results in the specified defensive sample', function (int $id, string $package, int $dbs) {
    [$target, $teams] = matchupSignalLeague();
    foreach ($teams as $i => $team) {
        $plays = DB::table('nflverse_pbp_plays')->where('possession_team_id', $team->id);
        $plays->update(['participation_offense_package' => 'OTHER', 'participation_defense_dbs' => $dbs]);
        foreach ((clone $plays)->get()->groupBy('nfl_game_id') as $rows) {
            DB::table('nflverse_pbp_plays')->whereIn('id', $rows->take(10 + intdiv($i, 8) * 10)->pluck('id'))
                ->update(['participation_offense_package' => $package]);
        }
    }
    $target->away_team_id = $teams[1]->id;
    $service = app(NflMatchupSignalService::class);
    $signal = matchupSignal($service->build($target), $id, $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['offense']['value'])->toBe(1.0)
        ->and($signal['evidence']['defense']['value'])->toEqualWithDelta(.14, .000001);
    $field = $id === 274 ? 'participation_offense_package' : 'participation_defense_dbs';
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->update([$field => null]);
    expect(matchupSignal($service->build($target), $id, $target->home_team_id)['status'])->toBe('insufficient_data');
})->with([[274, '12', 4], [275, '11', 5], [276, '21', 4]]);

it('requires a known available selected QB and never silently borrows historical pressure charting', function () {
    [$target, , $games] = matchupSignalLeague();
    DB::table('nflverse_pbp_plays')->update(['participation_pressure' => true, 'participation_rushers' => 4]);
    foreach ($games as $game) {
        $game->update(['season' => 2025, 'game_date' => $game->game_date->subYear()]);
    }
    $result = app(NflMatchupSignalService::class)->build($target);
    foreach ([176, 197, 198, 253, 274, 275, 276] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('insufficient_data');
    }
});

it('excludes passes without a receiver from WR target samples while withholding unidentified receiver positions', function () {
    [$target] = participationMatchupLeague();
    $service = app(NflMatchupSignalService::class);
    $ids = DB::table('nflverse_pbp_plays')->where('possession_team_id', $target->home_team_id)->where('play_type', 'pass')
        ->where('is_sack', false)->get()->groupBy('nfl_game_id');
    foreach ($ids as $rows) {
        DB::table('nflverse_pbp_plays')->whereIn('id', $rows->take(5)->pluck('id'))->update(['receiver_player_id' => null]);
    }
    expect(matchupSignal($service->build($target), 253, $target->home_team_id)['status'])->toBe('matched');
    foreach ($ids as $rows) {
        DB::table('nflverse_pbp_plays')->whereIn('id', $rows->take(5)->pluck('id'))->update(['receiver_player_id' => 'unmapped-receiver']);
    }
    expect(matchupSignal($service->build($target), 253, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('evaluates ten published win-rate comparisons without deriving ranks from rounded values', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC'));
    [$target, $teams] = matchupSignalLeague();
    $samples = [];
    foreach ($teams as $index => $team) {
        $samples[$team->id] = [
            'PBWR' => ['value' => .50, 'rank' => 32 - $index],
            'PRWR' => ['value' => .50, 'rank' => 32 - $index],
            'RBWR' => ['value' => .50, 'rank' => 32 - $index],
            'RSWR' => ['value' => .50, 'rank' => 32 - $index],
        ];
    }
    DB::table('nfl_matchup_win_rate_snapshots')->insert(['season' => 2026, 'through_week' => 3,
        'source_updated_at' => '2026-09-18 14:00:00', 'observed_at' => '2026-09-18 15:00:00',
        'source_url' => 'https://www.espn.com/nfl/story/test', 'content_hash' => str_repeat('a', 64), 'teams' => json_encode($samples)]);
    $service = app(NflMatchupSignalService::class);
    $rules = [141, 142, 143, 144, 145, 146, 147, 148, 172, 173];
    foreach ([[31, 30, [141, 145]], [31, 0, [142, 146, 172]], [0, 31, [143, 147, 173]], [0, 1, [144, 148]]] as [$home, $away, $matched]) {
        $target->update(['home_team_id' => $teams[$home]->id, 'away_team_id' => $teams[$away]->id]);
        $result = $service->build($target->fresh());
        foreach ($rules as $id) {
            $signal = matchupSignal($result, $id, $teams[$home]->id);
            expect($signal['status'])->toBe(in_array($id, $matched) ? 'matched' : 'not_matched')
                ->and($signal['evidence']['offense']['plays'])->toBeNull()
                ->and($signal['evidence']['offense']['display_value'])->toContain('through Week 3')
                ->and($result['predictive_weight'])->toBe(0);
        }
    }
    Game::factory()->create(['season' => 2026, 'season_type' => '2', 'week' => 4, 'game_date' => '2026-09-18',
        'game_time' => '17:00:00', 'status' => 'STATUS_FINAL', 'home_team_id' => $teams[2]->id, 'away_team_id' => $teams[3]->id]);
    $fallback = $service->build($target->fresh());
    expect($fallback['baseline']['through_week'])->toBe(3)
        ->and(matchupSignal($fallback, 144, $teams[0]->id)['status'])->toBe('matched');
    $target->update(['home_team_id' => $teams[27]->id, 'away_team_id' => $teams[31]->id]);
    $samples[$teams[26]->id]['PBWR']['rank'] = 5;
    DB::table('nfl_matchup_win_rate_snapshots')->update(['teams' => json_encode($samples)]);
    $result = $service->build($target->fresh());
    expect(matchupSignal($result, 141, $teams[27]->id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 145, $teams[27]->id)['status'])->toBe('matched');
    DB::table('nfl_matchup_win_rate_snapshots')->update(['observed_at' => '2026-09-21 00:00:00']);
    $result = $service->build($target->fresh());
    expect(matchupSignal($result, 141, $teams[27]->id)['status'])->toBe('insufficient_data');
});

function combinedWinRateSnapshot($teams, bool $reverse = false): void
{
    $samples = [];
    foreach ($teams as $index => $team) {
        $offenseRank = $reverse ? $index + 1 : 32 - $index;
        $defenseRank = 33 - $offenseRank;
        $samples[$team->id] = [
            'PBWR' => ['value' => .50, 'rank' => $offenseRank], 'PRWR' => ['value' => .50, 'rank' => $defenseRank],
            'RBWR' => ['value' => .50, 'rank' => $offenseRank], 'RSWR' => ['value' => .50, 'rank' => $defenseRank],
        ];
    }
    DB::table('nfl_matchup_win_rate_snapshots')->delete();
    DB::table('nfl_matchup_win_rate_snapshots')->insert(['season' => 2026, 'through_week' => 3,
        'source_updated_at' => '2026-09-18 14:00:00', 'observed_at' => '2026-09-18 15:00:00',
        'source_url' => 'https://www.espn.com/nfl/story/test', 'content_hash' => str_repeat('b', 64), 'teams' => json_encode($samples)]);
}

it('combines measured blocking and run stopping with the correct directions and non-neutral road venue', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    combinedWinRateSnapshot($teams);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    expect(matchupSignal($result, 135, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 136, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 135, $target->home_team_id)['evidence']['defense_metric'])->toBe('run_stop_win_rate');
    combinedWinRateSnapshot($teams, true);
    $result = $service->build($target);
    expect(matchupSignal($result, 136, $target->home_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 135, $target->home_team_id)['status'])->toBe('not_matched')
        ->and(matchupSignal($result, 183, $target->away_team_id)['status'])->toBe('matched')
        ->and(matchupSignal($result, 183, $target->home_team_id)['status'])->toBe('not_matched');
    $target->neutral_site = true;
    expect(matchupSignal($service->build($target), 183, $target->away_team_id)['status'])->toBe('not_matched');
    DB::table('nfl_matchup_win_rate_snapshots')->delete();
    expect(matchupSignal($service->build($target), 135, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('requires both pass-protection ranks and the selected quarterbacks own qualified sample', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupPositionLeague();
    $target->away_team_id = $teams[30]->id;
    combinedWinRateSnapshot($teams);
    $service = app(NflMatchupSignalService::class);
    $result = $service->build($target);
    foreach ([186, 187] as $id) {
        $signal = matchupSignal($result, $id, $target->home_team_id);
        expect($signal['status'])->toBe('matched')
            ->and($signal['evidence']['additional_condition']['sample']['player_id'])->toBe('00-10124')
            ->and($signal['evidence']['offense']['display_value'])->toContain('through Week 3')
            ->and($signal['evidence']['additional_condition']['matched'])->toBeTrue();
    }
    expect(matchupSignal($result, 185, $target->home_team_id)['status'])->toBe('not_matched');
    $target->home_qb_id = '00-10000';
    $result = $service->build($target);
    foreach ([185, 186, 187] as $id) {
        expect(matchupSignal($result, $id, $target->home_team_id)['status'])->toBe('not_matched');
    }
    combinedWinRateSnapshot($teams, true);
    expect(matchupSignal($service->build($target), 185, $target->home_team_id)['status'])->toBe('matched');
    $target->home_qb_id = null;
    expect(matchupSignal($service->build($target), 185, $target->home_team_id)['status'])->toBe('insufficient_data');
    $target->home_qb_id = '00-10124';
    combinedWinRateSnapshot($teams);
    DB::table('nflverse_pbp_plays')->where('possession_team_id', $teams[31]->id)->where('play_type', 'run')->update(['qb_scramble' => null]);
    expect(matchupSignal($service->build($target), 186, $target->home_team_id)['status'])->toBe('insufficient_data');
    DB::table('nflverse_pbp_plays')->whereIn('possession_team_id', $teams->take(9)->pluck('id'))->update(['passer_player_id' => null]);
    expect(matchupSignal($service->build($target), 187, $target->home_team_id)['status'])->toBe('insufficient_data');
});

it('requires the additional passing defense condition without treating a weak pass rush as weak pass coverage', function () {
    $this->travelTo('2026-09-19 12:00:00');
    [$target, $teams] = matchupSignalLeague();
    combinedWinRateSnapshot($teams);
    $service = app(NflMatchupSignalService::class);
    expect(matchupSignal($service->build($target), 188, $target->home_team_id)['status'])->toBe('not_matched');
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $target->away_team_id)->where('play_type', 'pass')->update(['epa' => .5]);
    $signal = matchupSignal($service->build($target), 188, $target->home_team_id);
    expect($signal['status'])->toBe('matched')
        ->and($signal['evidence']['additional_condition']['metric'])->toBe('pass_epa')
        ->and($signal['evidence']['offense']['display_value'])->toContain('passing EPA allowed');
    DB::table('nflverse_pbp_plays')->where('defense_team_id', $teams[0]->id)->where('play_type', 'pass')->update(['epa' => null]);
    expect(matchupSignal($service->build($target), 188, $target->home_team_id)['status'])->toBe('insufficient_data');
});
