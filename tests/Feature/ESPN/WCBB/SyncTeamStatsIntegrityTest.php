<?php

use App\Actions\ESPN\WCBB\SyncTeamStats;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;

beforeEach(function () {
    $this->home = Team::factory()->create(['espn_id' => 10001]);
    $this->away = Team::factory()->create(['espn_id' => 10002]);
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 80, 'away_score' => 75]);
});

function wcbbSyncSourceRow(Team $team, array $stats): array
{
    return ['team' => ['id' => (string) $team->espn_id], 'statistics' => collect($stats)
        ->map(fn ($value, $key): array => ['name' => $key, 'displayValue' => (string) $value])->values()->all()];
}

function wcbbValidSyncPayload(Team $home, Team $away): array
{
    return ['boxscore' => ['teams' => [
        wcbbSyncSourceRow($home, ['points' => 80, 'fieldGoalsAttempted' => 60, 'offensiveRebounds' => 8, 'turnovers' => 12, 'freeThrowsAttempted' => 20]),
        wcbbSyncSourceRow($away, ['points' => 75, 'fieldGoalsAttempted' => 58, 'offensiveRebounds' => 9, 'turnovers' => 10, 'freeThrowsAttempted' => 15]),
    ]]];
}

it('does not create fake zero point rows from empty summary statistics', function () {
    $payload = ['boxscore' => ['teams' => [wcbbSyncSourceRow($this->home, []), wcbbSyncSourceRow($this->away, [])]]];
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(0)
        ->and(TeamStat::query()->count())->toBe(0);
});

it('preserves previously stored valid rows when a later summary is empty or incomplete', function () {
    app(SyncTeamStats::class)->execute(wcbbValidSyncPayload($this->home, $this->away), $this->game);
    $before = TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = wcbbValidSyncPayload($this->home, $this->away);
    $payload['boxscore']['teams'][1]['statistics'] = [];
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(0)
        ->and(TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

it('stores complete paired scoring and possession evidence without filling absent fields', function () {
    expect(app(SyncTeamStats::class)->execute(wcbbValidSyncPayload($this->home, $this->away), $this->game))->toBe(2);
    $home = TeamStat::query()->where('team_id', $this->home->id)->sole();
    expect($home->points)->toBe(80)->and($home->possessions)->toBeNull()
        ->and($home->field_goals_attempted)->toBe(60)->and($home->field_goals_made)->toBeNull();
});

it('rejects mismatched final points and incomplete possession estimates', function (bool $mismatch) {
    $payload = wcbbValidSyncPayload($this->home, $this->away);
    $stats = $mismatch ? ['points' => 0, 'possessions' => 70] : ['points' => 80, 'fieldGoalsAttempted' => 60, 'freeThrowsAttempted' => 20];
    $payload['boxscore']['teams'][0] = wcbbSyncSourceRow($this->home, $stats);
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(0)->and(TeamStat::query()->count())->toBe(0);
})->with([true, false]);

it('allows an actual zero point final when observed possession evidence is valid', function () {
    $this->game->update(['home_score' => 0]);
    $payload = wcbbValidSyncPayload($this->home, $this->away);
    $payload['boxscore']['teams'][0] = wcbbSyncSourceRow($this->home, ['points' => 0, 'possessions' => 70]);
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(2)
        ->and(TeamStat::query()->where('team_id', $this->home->id)->sole()->points)->toBe(0);
});

it('does not coerce unknown raw required values into usable zeroes', function (string $name, string $value) {
    app(SyncTeamStats::class)->execute(wcbbValidSyncPayload($this->home, $this->away), $this->game);
    $before = TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = wcbbValidSyncPayload($this->home, $this->away);
    $payload['boxscore']['teams'][0]['statistics'][] = ['name' => $name, 'displayValue' => $value];
    expect(app(SyncTeamStats::class)->execute($payload, $this->game))->toBe(0)
        ->and(TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with([
    ['offensiveRebounds', 'N/A'], ['turnovers', ''], ['possessions', '-'],
    ['fieldGoalsMade-fieldGoalsAttempted', '-'], ['fieldGoalsMade-fieldGoalsAttempted', 'N/A-60'],
]);

it('rolls back replacement if a paired row fails during persistence', function () {
    app(SyncTeamStats::class)->execute(wcbbValidSyncPayload($this->home, $this->away), $this->game);
    $before = TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all();
    $awayId = $this->away->id;
    TeamStat::creating(function (TeamStat $stat) use ($awayId): void {
        if ($stat->team_id === $awayId) {
            throw new RuntimeException('Simulated persistence failure.');
        }
    });
    expect(fn () => app(SyncTeamStats::class)->execute(wcbbValidSyncPayload($this->home, $this->away), $this->game))->toThrow(RuntimeException::class)
        ->and(TeamStat::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});
