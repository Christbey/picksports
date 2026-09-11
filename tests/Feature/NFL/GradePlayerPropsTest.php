<?php

use App\Actions\ESPN\NFL\SyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'season' => 2026, 'season_type' => 2, 'home_score' => 13, 'away_score' => 10]);
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Sam Darnold']);
    $this->stat = PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->team->id,
        'player_id' => $this->player->id, 'passing_attempts' => 30, 'passing_completions' => 20,
        'passing_yards' => 220, 'passing_touchdowns' => 2, 'interceptions_thrown' => 0,
        'rushing_attempts' => 3, 'rushing_yards' => 9, 'rushing_touchdowns' => 0,
        'receptions' => 2, 'receiving_yards' => 15, 'receiving_touchdowns' => 1]);
    $this->prop = fn ($market, $line = 0.5) => PlayerProp::create(['game_id' => $this->game->id,
        'player_id' => $this->player->id, 'player_name' => 'Sam Darnold', 'market' => $market, 'line' => $line]);
});

test('grades NFL markets from their corresponding stats', function ($market, $actual) {
    $prop = ($this->prop)($market);
    $result = app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    expect($result['graded'])->toBe(1)->and((float) $prop->fresh()->actual_value)->toBe((float) $actual);
    expect(app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id)['graded'])->toBe(0);
})->with([
    ['player_pass_attempts', 30], ['player_pass_completions', 20], ['player_pass_yds', 220],
    ['player_pass_tds', 2], ['player_pass_interceptions', 0], ['player_rush_attempts', 3],
    ['player_rush_yds', 9], ['player_receptions', 2], ['player_reception_yds', 15], ['player_anytime_td', 1],
]);

test('counts return touchdowns but excludes passing touchdowns from anytime scoring', function () {
    $this->stat->update(['receiving_touchdowns' => 0, 'punt_returns' => 2, 'punt_return_touchdowns' => 1]);
    $prop = ($this->prop)('player_anytime_td');
    app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    expect((float) $prop->fresh()->actual_value)->toBe(1.0);
});

test('leaves missing data unsupported markets and nonparticipants ungraded', function () {
    $this->stat->update(['passing_yards' => null]);
    $missing = ($this->prop)('player_pass_yds');
    $unsupported = ($this->prop)('player_first_td');
    $noLine = ($this->prop)('player_receptions', null);
    app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    foreach ([$missing, $unsupported, $noLine] as $prop) {
        expect($prop->fresh()->graded_at)->toBeNull();
    }
    $this->stat->delete();
    $nonparticipant = ($this->prop)('player_anytime_td');
    app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    expect($nonparticipant->fresh()->graded_at)->toBeNull();
});

test('does not mark a push as an under result or include it in calibration', function () {
    $prop = ($this->prop)('player_receptions', 2);
    $prop->update(['predicted_over_probability' => 60]);
    app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    expect($prop->fresh()->graded_at)->not->toBeNull()->and($prop->fresh()->hit_over)->toBeNull();
    expect(app(GradePlayerProps::class)->calculateBrierScore('americanfootball_nfl')['sample_size'])->toBe(0);
});

test('requires an unambiguous exact normalized name when the player link is missing', function () {
    $exact = ($this->prop)('player_receptions');
    $exact->update(['player_id' => null, 'player_name' => 'Sam Darnold']);
    $similar = ($this->prop)('player_receptions');
    $similar->update(['player_id' => null, 'player_name' => 'Sam Arnold']);
    app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id);
    expect($exact->fresh()->graded_at)->not->toBeNull()->and($similar->fresh()->graded_at)->toBeNull();
});

test('does not grade before a game is final', function () {
    $this->game->update(['status' => 'STATUS_IN_PROGRESS']);
    $prop = ($this->prop)('player_receptions');
    expect(app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $this->game->id)['graded'])->toBe(0);
});

test('grades props after completed game detail ingestion', function () {
    $prop = ($this->prop)('player_receptions');
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->with($this->game->espn_event_id)->andReturn(['boxscore' => []]);
    $stats = Mockery::mock();
    $stats->shouldReceive('execute')->once()->andReturn(1);
    $teams = Mockery::mock();
    $teams->shouldReceive('execute')->once()->andReturn(2);
    $plays = Mockery::mock();
    $plays->shouldReceive('execute')->once()->andReturn(1);
    $sync = new SyncGameDetails($service, $stats, $teams, $plays);
    $sync->execute($this->game->espn_event_id);
    expect($prop->fresh()->graded_at)->not->toBeNull();
});
