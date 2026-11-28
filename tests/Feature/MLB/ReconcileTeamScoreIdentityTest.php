<?php

use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;

it('refuses an unrelated team stat row even when its home-away label matches', function (bool $dryRun) {
    $teams = Team::factory()->count(3)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'status' => 'STATUS_FINAL', 'home_score' => null, 'away_score' => null]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[2]->id, 'team_type' => 'home', 'runs' => 6]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[1]->id, 'team_type' => 'away', 'runs' => 2]);
    $before = $game->fresh()->getAttributes();
    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game, dryRun: $dryRun);
    expect($result['status'])->toBe('skipped')->and($result['reason'])->toBe('missing_team_stats_runs')
        ->and($game->fresh()->getAttributes())->toBe($before);
})->with([true, false]);

it('preserves genuine zero runs from the exact home and away identities', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'status' => 'STATUS_FINAL', 'home_score' => null, 'away_score' => null]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[0]->id, 'team_type' => 'home', 'runs' => 0]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[1]->id, 'team_type' => 'away', 'runs' => 2]);
    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);
    expect($result['status'])->toBe('updated')->and($game->fresh()->home_score)->toBe(0)->and($game->fresh()->away_score)->toBe(2);
});
