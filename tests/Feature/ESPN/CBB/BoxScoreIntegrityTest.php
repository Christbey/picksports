<?php

use App\Actions\ESPN\CBB\SyncPlayerStats;
use App\Actions\ESPN\CBB\SyncTeamStats;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;

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
