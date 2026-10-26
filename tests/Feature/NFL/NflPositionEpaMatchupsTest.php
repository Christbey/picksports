<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflPositionEpaMatchups;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->teams = Team::factory()->count(2)->create();
    $this->games = collect();
    $plays = [];
    foreach ($this->teams as $team) {
        foreach (['WR', 'TE', 'RB'] as $position) {
            DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => $team->id.'-'.$position, 'season' => 2026,
                'team_id' => $team->id, 'gsis_id' => $team->id.'-'.$position, 'position' => $position]);
        }
    }
    foreach ([1, 2, 3] as $week) {
        $game = Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[1]->id,
            'season' => 2026, 'season_type' => 2, 'week' => $week, 'game_date' => '2026-09-0'.$week, 'status' => 'STATUS_FINAL']);
        $this->games->push($game);
        foreach ([[0, 1], [1, 0]] as [$a, $b]) {
            foreach (['WR', 'TE', 'RB', 'run', 'sack'] as $position) {
                foreach (range(1, $position === 'sack' ? 1 : 8) as $n) {
                    $plays[] = ['nflverse_play_key' => $game->id.'-'.$a.'-'.$position.'-'.$n,
                        'nfl_game_id' => $game->id, 'possession_team_id' => $this->teams[$a]->id, 'defense_team_id' => $this->teams[$b]->id,
                        'play_type' => $position === 'run' ? 'run' : 'pass', 'is_sack' => $position === 'sack',
                        'receiver_player_id' => in_array($position, ['WR', 'TE', 'RB']) ? $this->teams[$a]->id.'-'.$position : null,
                        'complete_pass' => false, 'epa' => $n === 1 ? 0 : -.2];
                }
            }
        }
    }
    DB::table('nflverse_pbp_plays')->insert($plays);
    $this->target = Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[1]->id,
        'season' => 2026, 'season_type' => 2, 'game_date' => '2026-10-25', 'status' => 'STATUS_SCHEDULED']);
    $this->read = fn () => app(NflPositionEpaMatchups::class)->build($this->target, $this->games);
});

it('counts incomplete targets and zero EPA separately by position and includes sacks in passing', function () {
    $result = ($this->read)();
    $splits = collect($result['directions'][0]['splits'])->keyBy('split');
    expect($splits['WR']['offense']['plays'])->toBe(24)
        ->and($splits['WR']['defense']['epa_per_play'])->toBe(-.175)
        ->and($splits['WR']['defense']['eligible'])->toBeTrue()
        ->and($splits['WR']['defense']['rank'])->toBeNull()
        ->and($splits['pass']['defense']['plays'])->toBe(75)
        ->and($splits['run']['defense']['plays'])->toBe(24)
        ->and($splits['WR']['previous_opponents']['weighted_epa_allowed'])->toBe(-.175)
        ->and($result['mode'])->toBe('descriptive_only');
});

it('withholds ambiguous season team player mappings and reports coverage instead of guessing', function () {
    DB::table('nflverse_rosters')->insert(['nflverse_roster_key' => 'ambiguous', 'season' => 2026,
        'team_id' => $this->teams[0]->id, 'gsis_id' => $this->teams[0]->id.'-WR', 'position' => 'TE']);
    $split = collect(($this->read)()['directions'][1]['splits'])->firstWhere('split', 'WR');
    expect($split['defense']['eligible'])->toBeFalse()
        ->and($split['defense']['plays'])->toBe(0)
        ->and($split['defense']['target_mapping']['unmapped_named_targets'])->toBe(24);
});

it('excludes other seasons preseason future and unfinished games even when supplied by a caller', function () {
    $before = ($this->read)();
    foreach ([['season' => 2025], ['season_type' => 1], ['game_date' => '2026-10-26'], ['status' => 'STATUS_IN_PROGRESS']] as $extra) {
        $game = $this->games->first()->replicate();
        $game->espn_event_id = fake()->unique()->numerify('##########');
        $game->fill($extra)->save();
        $this->games->push($game);
    }
    expect(($this->read)())->toBe($before);
});

it('does not convert missing EPA into zero or hide missing game feeds', function () {
    DB::table('nflverse_pbp_plays')->where('nfl_game_id', $this->games->first()->id)->delete();
    DB::table('nflverse_pbp_plays')->where('receiver_player_id', $this->teams[0]->id.'-WR')->update(['epa' => null]);
    $split = collect(($this->read)()['directions'][1]['splits'])->firstWhere('split', 'WR');
    expect($split['defense']['epa_per_play'])->toBeNull()->and($split['defense']['eligible'])->toBeFalse()
        ->and($split['defense']['games_with_any_plays'])->toBe(2)->and($split['defense']['expected_team_games'])->toBe(3);
});
