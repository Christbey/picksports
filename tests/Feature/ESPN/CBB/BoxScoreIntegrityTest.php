<?php

use App\Actions\ESPN\CBB\SyncPlayerStats;
use App\Actions\ESPN\CBB\SyncTeamStats;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Services\CBB\CbbDataQuality;

beforeEach(function (): void {
    $this->home = Team::factory()->create(['espn_id' => '52']);
    $this->away = Team::factory()->create(['espn_id' => '150']);
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 10, 'away_score' => 8]);
});

function cbbIntegrityPayload(): array
{
    $teams = [];
    foreach (['52' => 4, '150' => 3] as $id => $made) {
        $stats = ['fieldGoalsMade' => $made, 'fieldGoalsAttempted' => 10,
            'threePointFieldGoalsMade' => 0, 'threePointFieldGoalsAttempted' => 2,
            'freeThrowsMade' => 2, 'freeThrowsAttempted' => 2, 'offensiveRebounds' => 1, 'turnovers' => 2];
        $teams[] = ['team' => ['id' => (string) $id], 'homeAway' => $id === 52 ? 'home' : 'away', 'statistics' => collect($stats)->map(fn ($value, $name) => ['name' => $name, 'displayValue' => (string) $value])->values()->all()];
    }

    return ['boxscore' => ['teams' => $teams]];
}

it('commits complete team boxes and preserves them when a later final payload is partial', function (): void {
    $action = app(SyncTeamStats::class);
    expect($action->execute(cbbIntegrityPayload(), $this->game))->toBe(2);
    $before = TeamStat::where('game_id', $this->game->id)->get()->toArray();
    $bad = cbbIntegrityPayload();
    $bad['boxscore']['teams'][0]['statistics'][0]['displayValue'] = '1';
    expect(fn () => $action->execute($bad, $this->game))->toThrow(RuntimeException::class, 'Rejected incomplete');
    expect(TeamStat::where('game_id', $this->game->id)->get()->toArray())->toBe($before);
});

it('does not create zero-stat rows for future games', function (): void {
    $this->game->update(['status' => 'STATUS_SCHEDULED']);
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => '52']], ['team' => ['id' => '150']]]]];
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(0)
        ->and(TeamStat::where('game_id', $this->game->id)->count())->toBe(0);
});

it('maps player columns by labels rather than assuming their positions', function (): void {
    $teams = [];
    foreach (['52' => 10, '150' => 8] as $id => $points) {
        $teams[] = ['team' => ['id' => (string) $id], 'statistics' => [[
            'labels' => ['PTS', 'MIN', 'FG', '3PT', 'FT', 'REB', 'AST', 'TO', 'STL', 'BLK', 'OREB', 'DREB', 'PF'],
            'athletes' => [['athlete' => ['id' => $id.'01', 'displayName' => 'Player '.$id],
                'stats' => [(string) $points, '40', '3-8', '0-2', ($points - 6).'-4', '7', '2', '1', '1', '0', '2', '5', '1']]],
        ]]];
    }
    expect(app(SyncPlayerStats::class)->execute(['boxscore' => ['players' => $teams]], $this->game))->toBe(2);
    $homeStats = $this->game->playerStats()->where('team_id', $this->home->id)->firstOrFail();
    expect($homeStats->points)->toBe(10)->and((string) $homeStats->minutes_played)->toBe('40');
    $teams[0]['statistics'][0]['athletes'][0]['stats'][0] = '2';
    expect(fn () => app(SyncPlayerStats::class)->execute(['boxscore' => ['players' => $teams]], $this->game))->toThrow(RuntimeException::class);
    expect($homeStats->fresh()->points)->toBe(10);
});

function cbbTeamCreditPlays(Game $game): void
{
    $rows = [['sequence_number' => 1, 'clock' => '20:00', 'period' => 1, 'home_score' => 0, 'away_score' => 0]];
    $home = $away = 0;
    for ($i = 0; $i < 10; $i++) {
        $isHome = $i < 5;
        $isTeam = $i === 9;
        $isHome ? $home += 2 : $away += 2;
        $rows[] = ['sequence_number' => $i + 2, 'period' => 2, 'clock' => '2:07',
            'possession_team_id' => $isHome ? $game->home_team_id : $game->away_team_id,
            'made_shot' => true, 'shooting_play' => true, 'score_value' => 2,
            'play_type' => $isTeam ? 'TipShot' : 'JumpShot',
            'play_text' => $isTeam ? 'team  makes 83-foot shot' : 'Named Player makes jumper',
            'home_score' => $home, 'away_score' => $away];
    }
    $rows[] = ['sequence_number' => 12, 'period' => 2, 'clock' => '0:00', 'home_score' => 10, 'away_score' => 10];
    foreach ($rows as $row) {
        Play::factory()->create(['game_id' => $game->id, ...$row]);
    }
}

it('reconciles explicit team points without assigning them to a player or changing shooting totals', function (): void {
    $this->game->update(['away_score' => 10]);
    cbbTeamCreditPlays($this->game);
    $teams = [];
    foreach (['52' => 10, '150' => 8] as $id => $points) {
        $teams[] = ['team' => ['id' => (string) $id], 'statistics' => [[
            'labels' => ['MIN', 'PTS', 'FG', '3PT', 'FT', 'REB', 'AST', 'TO', 'STL', 'BLK', 'OREB', 'DREB', 'PF'],
            'athletes' => [['athlete' => ['id' => $id.'01', 'displayName' => 'Player '.$id],
                'stats' => ['40', (string) $points, '3-10', '0-2', ($points - 6).'-4', '7', '2', '1', '1', '0', '2', '5', '1']]],
        ]]];
    }
    expect(app(SyncPlayerStats::class)->execute(['boxscore' => ['players' => $teams]], $this->game))->toBe(2);
    expect(app(SyncTeamStats::class)->execute(cbbIntegrityPayload(), $this->game))->toBe(2);
    $away = $this->game->teamStats()->where('team_id', $this->away->id)->firstOrFail();
    expect($away->points)->toBe(10)->and($away->field_goals_made)->toBe(3)
        ->and($this->game->playerStats()->where('team_id', $this->away->id)->sum('points'))->toBe(8)
        ->and(app(CbbDataQuality::class)->boxScoreIssues($this->game, $this->game->teamStats()->get()))->toBe([]);
});

it('does not use team credits from incomplete or non-reconciling play evidence', function (string $failure): void {
    $this->game->update(['away_score' => 10]);
    cbbTeamCreditPlays($this->game);
    if ($failure === 'missing_end') {
        $this->game->plays()->where('sequence_number', 12)->delete();
    } elseif ($failure === 'missing_scoring_play') {
        $this->game->plays()->where('sequence_number', 2)->delete();
    } else {
        $this->game->plays()->where('sequence_number', 11)->update(['play_text' => 'Named Player makes tip shot']);
    }
    expect(fn () => app(SyncTeamStats::class)->execute(cbbIntegrityPayload(), $this->game))->toThrow(RuntimeException::class)
        ->and($this->game->teamStats()->count())->toBe(0);
})->with(['missing_end', 'missing_scoring_play', 'named_player']);
