<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use App\Services\CFB\Predictions\CfbLargeSpreadEvidence;
use Carbon\CarbonImmutable;

it('freezes observed attempts and regulation fourth quarters without importing later corrections', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00'));
    [$home, $away] = Team::factory()->count(2)->create()->all();
    $old = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2026-08-29', 'game_time' => '12:00:00', 'status' => 'STATUS_FINAL', 'home_score' => 35, 'away_score' => 14,
        'home_linescores' => [['value' => 14], ['value' => 14], ['value' => 7], ['value' => 0]],
        'away_linescores' => [0, 0, 7, 7]]);
    TeamStat::factory()->create(['game_id' => $old->id, 'team_id' => $home->id, 'team_type' => 'home', 'passing_attempts' => 30, 'rushing_attempts' => 40]);
    $target = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2026-09-05', 'status' => 'STATUS_SCHEDULED']);
    $capture = CarbonImmutable::parse('2026-09-02 12:00:00');
    $cutoff = CarbonImmutable::parse('2026-09-05 12:00:00');
    $result = app(CfbLargeSpreadEvidence::class)->forGame($target, $home->id, $capture, $cutoff);
    expect($result['pace']['plays_proxy_per_game'])->toBe(70.0)
        ->and($result['late_game']['fourth_quarter_margin'])->toBe(-7.0)
        ->and($result['late_game']['entered_fourth_leading_20_plus_games'])->toBe(1)
        ->and($result['applied_to_prediction'])->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-09-03 12:00:00'));
    $old->update(['away_score' => 21]);
    $result = app(CfbLargeSpreadEvidence::class)->forGame($target, $home->id, $capture, $cutoff);
    expect($result['eligible_games'])->toBe(0)->and($result['pace']['plays_proxy_per_game'])->toBeNull();
});

it('does not treat missing or malformed linescores as zero fourth-quarter scoring', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01'));
    [$home, $away] = Team::factory()->count(2)->create()->all();
    Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2026-08-29', 'game_time' => '12:00:00', 'status' => 'STATUS_FINAL', 'home_score' => 35, 'away_score' => 14,
        'home_linescores' => [['value' => 14], ['value' => 14], ['value' => 7], ['displayValue' => '0']], 'away_linescores' => [0, 0, 7, 7]]);
    $target = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-05', 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $result = app(CfbLargeSpreadEvidence::class)->forGame($target, $home->id, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-05'));
    expect($result['late_game']['sample_games'])->toBe(0)->and($result['late_game']['fourth_quarter_margin'])->toBeNull()
        ->and($result['pace']['sample_games'])->toBe(0);
});
