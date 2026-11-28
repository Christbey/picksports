<?php

use App\Actions\ESPN\WCBB\SyncTeamStats;
use App\Models\WCBB\TeamStat;

function basketballStatIntegrityFixture(string $sport): array
{
    $prefix = 'App\\Models\\'.$sport.'\\';
    $teamClass = $prefix.'Team';
    $gameClass = $prefix.'Game';
    $playerClass = $prefix.'Player';
    $statClass = $prefix.'PlayerStat';
    $home = $teamClass::factory()->create(['espn_id' => 'integrity-home']);
    $away = $teamClass::factory()->create(['espn_id' => 'integrity-away']);
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_IN_PROGRESS']);
    $labels = ['MIN', 'PTS', 'FG', '3PT', 'FT', 'REB', 'AST', 'TO', 'STL', 'BLK', 'OREB', 'DREB', 'PF'];
    $sections = [];
    foreach ([$home, $away] as $index => $team) {
        $player = $playerClass::factory()->create(['team_id' => $team->id, 'espn_id' => 'integrity-athlete-'.$index]);
        $statClass::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id, 'points' => 25]);
        $sections[] = ['team' => ['id' => $team->espn_id], 'statistics' => [['labels' => $labels, 'athletes' => [[
            'athlete' => ['id' => $player->espn_id, 'displayName' => 'Synthetic Player'],
            'stats' => ['15', '8', '3-5', '1-2', '1-2', '2', '1', '0', '0', '0', '1', '1', '1'],
        ]]]]];
    }

    return [$game, $statClass, $playerClass, ['boxscore' => ['players' => $sections]], app('App\\Actions\\ESPN\\'.$sport.'\\SyncPlayerStats')];
}

it('preserves the complete prior batch when a basketball row lacks trustworthy labeled statistics', function ($sport, $defect) {
    [$game, $stats, $players, $payload, $action] = basketballStatIntegrityFixture($sport);
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $category = &$payload['boxscore']['players'][1]['statistics'][0];
    $row = &$category['athletes'][0];
    match ($defect) {
        'truncated' => $row['stats'] = ['15'],
        'blank' => $row['stats'][1] = '',
        'nonnumeric' => $row['stats'][5] = '--',
        'missing attempt' => $row['stats'][2] = '0',
        'impossible attempt' => $row['stats'][2] = '7-2',
        'empty unmarked' => $row['stats'] = [],
        'missing labels' => $category['labels'] = [],
        'duplicate labels' => $category['labels'][1] = 'MIN',
        'missing required label' => $category['labels'][1] = 'UNKNOWN',
    };
    try {
        $synced = $action->execute($payload, $game);
    } catch (RuntimeException $exception) {
        if ($sport !== 'CBB') {
            throw $exception;
        }
        $synced = 0;
    }
    expect($synced)->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['NBA', 'CBB', 'WNBA', 'WCBB'])->with(['truncated', 'blank', 'nonnumeric', 'missing attempt', 'impossible attempt', 'empty unmarked', 'missing labels', 'duplicate labels', 'missing required label']);

it('maps basketball statistics by labels rather than position', function ($sport) {
    [$game, $stats, , $payload, $action] = basketballStatIntegrityFixture($sport);
    foreach ($payload['boxscore']['players'] as &$section) {
        $section['statistics'][0]['labels'] = array_reverse($section['statistics'][0]['labels']);
        $section['statistics'][0]['athletes'][0]['stats'] = array_reverse($section['statistics'][0]['athletes'][0]['stats']);
    }
    unset($section);
    expect($action->execute($payload, $game))->toBe(2);
    foreach ($stats::where('game_id', $game->id)->get() as $stat) {
        expect($stat->points)->toBe(8)->and($stat->field_goals_made)->toBe(3)
            ->and($stat->field_goals_attempted)->toBe(5)->and($stat->rebounds_total)->toBe(2)->and($stat->assists)->toBe(1);
    }
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('preserves genuinely explicit zero statistics and keeps DNP athletes separate', function ($sport) {
    [$game, $stats, $players, $payload, $action] = basketballStatIntegrityFixture($sport);
    foreach ($payload['boxscore']['players'] as &$section) {
        $section['statistics'][0]['athletes'][0]['stats'] = ['0', '0', '0-0', '0-0', '0-0', '0', '0', '0', '0', '0', '0', '0', '0'];
        $section['statistics'][0]['athletes'][] = ['athlete' => ['id' => 'dnp-'.$section['team']['id']], 'didNotPlay' => true, 'stats' => []];
    }
    unset($section);
    expect($action->execute($payload, $game))->toBe(2)->and($players::where('espn_id', 'like', 'dnp-%')->count())->toBe(0);
    foreach ($stats::where('game_id', $game->id)->get() as $stat) {
        expect($stat->points)->toBe(0)->and($stat->field_goals_attempted)->toBe(0)
            ->and($stat->rebounds_total)->toBe(0)->and($stat->assists)->toBe(0);
    }
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('does not replace valid basketball team points with partial or malformed derived shooting totals', function ($sport, $shooting) {
    [$game] = basketballStatIntegrityFixture($sport);
    $teamStats = 'App\\Models\\'.$sport.'\\TeamStat';
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $old = $teamStats::factory()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'team_type' => 'home', 'points' => 88]);
    $before = $old->fresh()->getAttributes();
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $teamClass::findOrFail($game->home_team_id)->espn_id],
        'statistics' => [...$shooting, ['name' => 'totalRebounds', 'displayValue' => '8']]]]]];
    $action = app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats');
    expect($action->execute($payload, $game))->toBe(0)->and($old->fresh()?->getAttributes())->toBe($before);
})->with(['NBA', 'CBB', 'WNBA', 'WCBB'])->with([
    [[['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '20-40']]],
    [[['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => ' - ']]],
    [[['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '12-3'],
        ['name' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'displayValue' => '1-3'],
        ['name' => 'freeThrowsMade-freeThrowsAttempted', 'displayValue' => '3-4']]],
]);

it('keeps partial new team observations unavailable instead of deriving invented points or possessions', function ($sport) {
    [$game] = basketballStatIntegrityFixture($sport);
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $statsClass = 'App\\Models\\'.$sport.'\\TeamStat';
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $teamClass::findOrFail($game->home_team_id)->espn_id], 'homeAway' => 'home',
        'statistics' => [['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '3-5']]]]]];
    if ($sport === 'WCBB') {
        expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(0)
            ->and($statsClass::where('game_id', $game->id)->count())->toBe(0);

        return;
    }
    expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(1);
    $stat = $statsClass::where('game_id', $game->id)->sole();
    expect($stat->points)->toBeNull()->and($stat->possessions)->toBeNull()
        ->and($stat->field_goals_made)->toBe(3)->and($stat->free_throws_made)->toBeNull();
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('derives genuine zero team points only from explicit complete shooting observations', function ($sport) {
    [$game] = basketballStatIntegrityFixture($sport);
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $statsClass = 'App\\Models\\'.$sport.'\\TeamStat';
    $statistics = array_map(fn ($name) => ['name' => $name, 'displayValue' => '0-0'],
        ['fieldGoalsMade-fieldGoalsAttempted', 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'freeThrowsMade-freeThrowsAttempted']);
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $teamClass::findOrFail($game->home_team_id)->espn_id], 'homeAway' => 'home', 'statistics' => $statistics]]]];
    if ($sport === 'WCBB') {
        expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(0)
            ->and($statsClass::where('game_id', $game->id)->count())->toBe(0);

        return;
    }
    expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(1)
        ->and($statsClass::where('game_id', $game->id)->sole()->points)->toBe(0);
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('validates all athlete rows before creating or updating any player identity', function () {
    [$game, , $players, $payload, $action] = basketballStatIntegrityFixture('NBA');
    $count = $players::count();
    $payload['boxscore']['players'][0]['statistics'][0]['athletes'][0]['athlete']['id'] = 'new-player-must-not-be-created';
    $payload['boxscore']['players'][1]['statistics'][0]['athletes'][0]['stats'] = ['15'];
    expect($action->execute($payload, $game))->toBe(0)->and($players::count())->toBe($count)
        ->and($players::where('espn_id', 'new-player-must-not-be-created')->exists())->toBeFalse();
});

it('keeps explicitly supplied team points without inventing missing shooting fields', function ($sport) {
    [$game] = basketballStatIntegrityFixture($sport);
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $statsClass = 'App\\Models\\'.$sport.'\\TeamStat';
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $teamClass::findOrFail($game->home_team_id)->espn_id], 'homeAway' => 'home',
        'statistics' => [['name' => 'points', 'displayValue' => '12']]]]]];
    if ($sport === 'WCBB') {
        expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(0)
            ->and($statsClass::where('game_id', $game->id)->count())->toBe(0);

        return;
    }
    expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(1);
    $stat = $statsClass::where('game_id', $game->id)->sole();
    expect($stat->points)->toBe(12)->and($stat->field_goals_made)->toBeNull()
        ->and($stat->free_throws_made)->toBeNull()->and($stat->possessions)->toBeNull();
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('validates replacement team identity from the current locked game rather than a stale caller', function ($sport) {
    [$game, $stats, , $payload, $action] = basketballStatIntegrityFixture($sport);
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $replacement = $teamClass::factory()->create();
    $game::whereKey($game->id)->update(['away_team_id' => $replacement->id]);
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['NBA', 'CBB', 'WNBA', 'WCBB']);

it('retains genuine zero WCBB scoring with paired complete possession observations', function () {
    [$game] = basketballStatIntegrityFixture('WCBB');
    $sections = [];
    foreach ([$game->homeTeam, $game->awayTeam] as $team) {
        $sections[] = ['team' => ['id' => $team->espn_id], 'statistics' => [
            ['name' => 'points', 'displayValue' => '0'],
            ['name' => 'possessions', 'displayValue' => '10'],
            ['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '0-0'],
            ['name' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'displayValue' => '0-0'],
            ['name' => 'freeThrowsMade-freeThrowsAttempted', 'displayValue' => '0-0'],
        ]];
    }
    expect(app(SyncTeamStats::class)->execute(['boxscore' => ['teams' => $sections]], $game))->toBe(2)
        ->and(TeamStat::where('game_id', $game->id)->pluck('points')->all())->toBe([0, 0]);
});
