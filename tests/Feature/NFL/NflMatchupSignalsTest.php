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
    expect($result['summary']['supported_rules'])->toBe(52)
        ->and($result['signals'])->toHaveCount(104)
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
        ->and(count($queries))->toBe(2);
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

it('withholds league rank signals when the league is incomplete or a team has only two games', function () {
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
