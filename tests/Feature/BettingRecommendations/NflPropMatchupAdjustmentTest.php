<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\NflPropMatchupAdjustment;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\NflPropProbabilityExperiment;

beforeEach(function () {
    $this->teams = Team::factory()->count(6)->create();
    $this->receivers = $this->teams->map(fn ($team) => Player::factory()->create(['team_id' => $team->id, 'position' => 'WR']));
    $this->target = Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[1]->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-25']);
    for ($i = 0; $i < 6; $i++) {
        for ($j = $i + 1; $j < 6; $j++) {
            $game = Game::factory()->create(['home_team_id' => $this->teams[$i]->id, 'away_team_id' => $this->teams[$j]->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => sprintf('2026-09-%02d', $i + $j + 1)]);
            foreach ([[$i, $j], [$j, $i]] as [$offense, $defense]) {
                PlayerStat::factory()->create(['game_id' => $game->id, 'team_id' => $this->teams[$offense]->id, 'player_id' => $this->receivers[$offense]->id, 'receptions' => 6, 'receiving_targets' => 8, 'receiving_yards' => $defense === 1 ? 50 : ($defense === 2 ? 150 : 100)]);
            }
        }
    }
    $this->history = fn () => PlayerStat::with('game')->where('player_id', $this->receivers[0]->id)->get();
    $this->resolve = fn ($market = 'player_reception_yds') => app(NflPropMatchupAdjustment::class)->resolve($this->target, $this->receivers[0], $market, ($this->history)());
});

test('a strong yardage defense lowers the projection without assuming fewer catches', function () {
    $yards = ($this->resolve)();
    $catches = ($this->resolve)('player_receptions');
    expect($yards['status'])->toBe('applied')
        ->and($yards['opponent']['rank'])->toBe(1)
        ->and($yards['factor'])->toBeLessThan(1.0)
        ->and($yards['average_opponent_adjusted'])->toBeGreaterThan($yards['opponent']['comparison_average'])
        ->and($yards['ranked_teams'])->toBe(6)
        ->and($yards['covered_history_games'])->toBe(5)
        ->and($catches['factor'])->toBe(1.0);
    $this->target->away_team_id = $this->teams[2]->id;
    expect(($this->resolve)()['factor'])->toBeGreaterThan(1.0);
});

test('rankings and adjustments exclude prior seasons preseason future and unfinished games', function () {
    $before = ($this->resolve)();
    foreach ([['season' => 2025], ['season_type' => 1], ['game_date' => '2026-10-26'], ['status' => 'STATUS_IN_PROGRESS']] as $extra) {
        $game = Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[1]->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-01', ...$extra]);
        PlayerStat::factory()->create(['game_id' => $game->id, 'team_id' => $this->teams[0]->id, 'player_id' => $this->receivers[0]->id, 'receptions' => 100, 'receiving_targets' => 100, 'receiving_yards' => 10000]);
    }
    expect(($this->resolve)())->toBe($before);
});

test('missing position stats stay unavailable rather than becoming a favorable defense', function () {
    PlayerStat::where('team_id', $this->teams[0]->id)->update(['receiving_yards' => null]);
    $result = ($this->resolve)();
    expect($result['factor'])->toBe(1.0)->and($result['status'])->toBe('unavailable')->and($result['historical_games'])->toBe(0);
});

test('reported zero remains in positional allowance and game evidence', function () {
    $row = PlayerStat::where('player_id', $this->receivers[0]->id)->first();
    $row->update(['receiving_yards' => 0]);
    $result = ($this->resolve)();
    expect($result['historical_games'])->toBe(5)
        ->and(collect($result['comparisons'])->firstWhere('game_id', $row->game_id)['player_value'])->toBe(0.0);
});

test('a receiver is not assigned tight end or running back defensive production', function () {
    $before = ($this->resolve)();
    $tightEnd = Player::factory()->create(['team_id' => $this->teams[0]->id, 'position' => 'TE']);
    foreach (($this->history)() as $row) {
        PlayerStat::factory()->create(['game_id' => $row->game_id, 'team_id' => $row->team_id, 'player_id' => $tightEnd->id, 'receiving_yards' => 500, 'receptions' => 30, 'receiving_targets' => 30]);
    }
    expect(($this->resolve)())->toBe($before);
});

test('opposing offense quality is measured outside games against the defense being ranked', function () {
    $result = ($this->resolve)();
    expect($result['opponent']['allowed_average'])->toBe(50.0)
        ->and($result['opponent']['opposing_offense_average'])->toBeGreaterThan(100)
        ->and($result['opponent']['games'])->toBe(5)
        ->and($result['opponent']['game_ids'])->toHaveCount(5);
});

test('matchup adjustments are limited to the experiment collector while published calculations stay unchanged', function () {
    $published = app(PlayerPropAnalyzer::class);
    $experiment = app(NflPropProbabilityExperiment::class);
    $config = (new ReflectionMethod($published, 'getSportConfig'))->invoke($published, 'NFL');
    $args = [$this->target, $this->receivers[0]->id, $this->teams[0]->id, $this->teams[1]->id, 'player_reception_yds', $config];
    $baseline = (new ReflectionMethod($published, 'buildContextAdjustments'))->invoke($published, ...$args);
    $candidate = (new ReflectionMethod($experiment, 'buildContextAdjustments'))->invoke($experiment, ...$args);
    expect($baseline['opponent_factor'])->toEqual(1)
        ->and($candidate['opponent_factor'])->toBeLessThan(1.0)
        ->and($candidate['opponent_evidence']['position'])->toBe('WR')
        ->and(PlayerPropAnalyzer::nflModelVersion())->toBe('nfl-player-prop-v4-integrity');
});
