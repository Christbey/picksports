<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\NflGameQuarterbackIdentitySync;

it('does not certify fractional supplied scores as matching an integer final', function (string $homeScore) {
    $game = new Game(['status' => 'STATUS_FINAL', 'game_date' => '2026-10-04', 'season' => 2026, 'season_type' => 2, 'week' => 4, 'home_score' => 14, 'away_score' => 7]);
    $game->setRelation('homeTeam', new Team(['abbreviation' => 'KC']));
    $game->setRelation('awayTeam', new Team(['abbreviation' => 'BUF']));
    $row = ['gameday' => '2026-10-04', 'season' => '2026', 'week' => '4', 'game_type' => 'REG', 'home_team' => 'KC', 'away_team' => 'BUF', 'home_score' => $homeScore, 'away_score' => '7'];
    expect((new NflGameQuarterbackIdentitySync)->matchesFinalGame($game, $row))->toBeFalse();
})->with(['14.9', '14.0000000000000001']);

it('requires a real source calendar date rather than parser rollover', function () {
    $game = new Game(['status' => 'STATUS_FINAL', 'game_date' => '2026-03-02', 'season' => 2026, 'season_type' => 2, 'week' => 4, 'home_score' => 14, 'away_score' => 7]);
    $game->setRelation('homeTeam', new Team(['abbreviation' => 'KC']));
    $game->setRelation('awayTeam', new Team(['abbreviation' => 'BUF']));
    $row = ['gameday' => '2026-02-30', 'season' => '2026', 'week' => '4', 'game_type' => 'REG', 'home_team' => 'KC', 'away_team' => 'BUF', 'home_score' => '14', 'away_score' => '7'];
    expect((new NflGameQuarterbackIdentitySync)->matchesFinalGame($game, $row))->toBeFalse();
});

it('does not persist quarterback names from a fractional-score source match', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['abbreviation' => 'KC'])->id,
        'away_team_id' => Team::factory()->create(['abbreviation' => 'BUF'])->id, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-04', 'season' => 2026, 'season_type' => 2, 'week' => 4, 'home_score' => 14, 'away_score' => 7,
        'home_qb_id' => null, 'away_qb_id' => null, 'home_qb_name' => null, 'away_qb_name' => null]);
    $before = $game->fresh()->getAttributes();
    $csv = "game_id,espn,season,game_type,week,gameday,home_team,away_team,home_score,away_score,home_qb_id,away_qb_id,home_qb_name,away_qb_name\n".
        'synthetic,'.$game->espn_event_id.",2026,REG,4,2026-10-04,KC,BUF,14.9,7,00-0000001,00-0000002,Home Synthetic,Away Synthetic\n";
    $result = app(NflGameQuarterbackIdentitySync::class)->sync($csv, 2026, true);
    expect($result['changed_games'])->toBe(0)->and($result['unresolved_games'])->toBe(1)
        ->and($game->fresh()->getAttributes())->toBe($before);
});

it('preserves observed whole decimal and zero scores for exact final identity', function (int $score, string $sourceScore) {
    $game = new Game(['status' => 'STATUS_FINAL', 'game_date' => '2026-10-04', 'season' => 2026, 'season_type' => 2, 'week' => 4, 'home_score' => $score, 'away_score' => 7]);
    $game->setRelation('homeTeam', new Team(['abbreviation' => 'KC']));
    $game->setRelation('awayTeam', new Team(['abbreviation' => 'BUF']));
    $row = ['gameday' => '2026-10-04', 'season' => '2026', 'week' => '4', 'game_type' => 'REG', 'home_team' => 'KC', 'away_team' => 'BUF', 'home_score' => $sourceScore, 'away_score' => '7'];
    expect((new NflGameQuarterbackIdentitySync)->matchesFinalGame($game, $row))->toBeTrue();
})->with([[14, '14.0'], [0, '0']]);
