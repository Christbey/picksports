<?php

use App\Actions\GradePlayerProps;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 90]);
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'first_name' => 'Jordan', 'last_name' => 'Smith']);
    $this->stat = PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->team->id,
        'player_id' => $this->player->id, 'points' => 20, 'rebounds_total' => 5, 'assists' => 3]);
    $this->prop = fn (array $attributes = []) => PlayerProp::create([...['game_id' => $this->game->id,
        'player_id' => $this->player->id, 'player_name' => 'Jordan Smith', 'market' => 'player_points', 'line' => 19.5], ...$attributes]);
});

it('keeps absent scalar and composite components pending but admits recorded zero', function ($market, $field) {
    $this->stat->update([$field => null]);
    $prop = ($this->prop)(['market' => $market]);
    expect(app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(0)
        ->and($prop->fresh()->graded_at)->toBeNull();
    $this->stat->update([$field => 0]);
    expect(app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(1);
})->with([['player_points', 'points'], ['player_points_rebounds_assists', 'assists'],
    ['player_points_rebounds', 'rebounds_total'], ['player_points_assists', 'points'],
    ['player_rebounds_assists', 'assists'], ['player_blocks_steals', 'blocks']]);

it('preserves canonical identity when its game stat is missing', function () {
    $absent = Player::factory()->create(['team_id' => $this->team->id]);
    $prop = ($this->prop)(['player_id' => $absent->id]);
    expect(app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(0)
        ->and($prop->fresh()->graded_at)->toBeNull();
});

it('rejects ambiguous exact names and approximate names while honoring a linked player', function () {
    $second = Player::factory()->create(['team_id' => $this->team->id, 'first_name' => 'Jordan', 'last_name' => 'Smith']);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->team->id, 'player_id' => $second->id, 'points' => 40]);
    $ambiguous = ($this->prop)(['player_id' => null]);
    $approximate = ($this->prop)(['player_id' => null, 'player_name' => 'Jordon Smith']);
    $linked = ($this->prop)(['player_name' => 'Provider typo']);
    expect(app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(1)
        ->and($ambiguous->fresh()->graded_at)->toBeNull()->and($approximate->fresh()->graded_at)->toBeNull()
        ->and((float) $linked->fresh()->actual_value)->toBe(20.0);
});

it('accepts a unique normalized exact name without a player link', function () {
    $prop = ($this->prop)(['player_id' => null, 'player_name' => 'JORDAN-SMITH']);
    expect(app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(1)
        ->and((float) $prop->fresh()->actual_value)->toBe(20.0);
});

it('keeps integer-line pushes outside over rates and probability calibration samples', function () {
    $push = ($this->prop)(['line' => 20, 'predicted_over_probability' => 80]);
    ($this->prop)(['line' => 19, 'predicted_over_probability' => 75]);
    ($this->prop)(['line' => 21, 'predicted_over_probability' => 25]);
    $result = app(GradePlayerProps::class)->executeForGame('basketball_nba', $this->game->id);
    expect($push->fresh()->hit_over)->toBeNull()->and($result['graded'])->toBe(3)
        ->and($result['decided_props'])->toBe(2)->and($result['pushes'])->toBe(1)->and($result['hit_rate'])->toBe(50.0)
        ->and($result['calibration_sample'])->toBe(2)->and($result['brier_score'])->toBe(0.0625);
    $market = app(GradePlayerProps::class)->getStatsByMarket('basketball_nba')->sole();
    expect($market['total_props'])->toBe(3)->and($market['decided_props'])->toBe(2)
        ->and($market['pushes'])->toBe(1)->and($market['hit_over_rate'])->toBe(50.0)
        ->and(app(GradePlayerProps::class)->getCalibrationBuckets('basketball_nba')->sum('sample_size'))->toBe(2);
});

it('excludes pushes for each supported basketball prop model', function ($namespace, $sport) {
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $playerClass = "App\\Models\\{$namespace}\\Player";
    $statClass = "App\\Models\\{$namespace}\\PlayerStat";
    $propClass = "App\\Models\\{$namespace}\\PlayerProp";
    $team = $teamClass::factory()->create();
    $game = $gameClass::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $teamClass::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 90]);
    $player = $playerClass::factory()->create(['team_id' => $team->id, 'espn_id' => 'integrity-player', 'full_name' => 'Linked player']);
    $statClass::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id, 'points' => 20]);
    $prop = $propClass::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Linked player',
        'market' => 'player_points', 'line' => 20, 'predicted_over_probability' => 80]);
    $result = app(GradePlayerProps::class)->executeForGame($sport, $game->id);
    expect($result['graded'])->toBe(1)->and($result['decided_props'])->toBe(0)->and($result['pushes'])->toBe(1)
        ->and($prop->fresh()->hit_over)->toBeNull()->and($result['calibration_sample'])->toBe(0);
})->with([['CBB', 'basketball_ncaab'], ['WNBA', 'basketball_wnba']]);
