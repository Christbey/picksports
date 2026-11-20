<?php

use App\Actions\ESPN\MLB\SyncPlays;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Support\Facades\DB;

function replacementFixture(string $sport): array
{
    $namespace = strtoupper($sport);
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $playerClass = "App\\Models\\{$namespace}\\Player";
    $statClass = "App\\Models\\{$namespace}\\PlayerStat";
    $actionClass = "App\\Actions\\ESPN\\{$namespace}\\SyncPlayerStats";
    $home = $teamClass::factory()->create(['espn_id' => '101']);
    $away = $teamClass::factory()->create(['espn_id' => '102']);
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $players = collect([$home, $away])->map(fn ($team, $index) => $playerClass::factory()->create(['team_id' => $team->id, 'espn_id' => (string) (201 + $index)]));
    $old = $statClass::factory()->create(['game_id' => $game->id, 'team_id' => $home->id, 'player_id' => $players[0]->id, ...($sport === 'mlb' ? ['stat_type' => 'batting'] : [])]);
    $sections = collect([$home, $away])->map(function ($team, $index) use ($sport, $players) {
        $category = match ($sport) {
            'nfl' => ['name' => 'passing', 'labels' => ['C/ATT', 'YDS'], 'stats' => ['2/3', '20']],
            'mlb' => ['type' => 'batting', 'labels' => ['AB', 'H'], 'stats' => ['3', '1']],
            default => ['stats' => ['15', '8', '3-5', '1-2', '1-2', '2', '1', '0', '0', '0', '1', '1', '1']],
        };
        $stats = $category['stats'];
        unset($category['stats']);

        return ['team' => ['id' => $team->espn_id], 'statistics' => [[...$category, 'athletes' => [['athlete' => ['id' => $players[$index]->espn_id], 'stats' => $stats]]]]];
    })->all();

    return [$game, $statClass, $actionClass, $old, ['boxscore' => ['players' => $sections]], $playerClass];
}

it('preserves complete observations when an athlete identity is missing', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = replacementFixture($sport);
    unset($payload['boxscore']['players'][1]['statistics'][0]['athletes'][0]['athlete']['id']);

    expect(app($actionClass)->execute($payload, $game))->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('rolls back every replacement row when a later model write fails', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = replacementFixture($sport);
    $writes = 0;
    $fail = true;
    $statClass::creating(function () use (&$writes, &$fail) {
        if ($fail && ++$writes === 2) {
            throw new RuntimeException('Injected replacement failure');
        }
    });
    try {
        expect(fn () => app($actionClass)->execute($payload, $game))->toThrow(RuntimeException::class, 'Injected replacement failure');
    } finally {
        $fail = false;
    }

    expect($old->fresh())->not->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('loads athlete identities in a batch and still emits model creation events', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload, $playerClass] = replacementFixture($sport);
    $reads = 0;
    $events = 0;
    $table = (new $playerClass)->getTable();
    DB::listen(function ($query) use (&$reads, $table) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"'.$table.'"')) {
            $reads++;
        }
    });
    $statClass::created(function () use (&$events) {
        $events++;
    });

    expect(app($actionClass)->execute($payload, $game))->toBe(2)
        ->and($reads)->toBe(1)
        ->and($events)->toBe(2)
        ->and($old->fresh())->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(2);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('does not erase the other team when a replacement omits its section', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = replacementFixture($sport);
    $playerClass = 'App\\Models\\'.strtoupper($sport).'\\Player';
    $away = $playerClass::where('espn_id', '202')->firstOrFail();
    $other = $statClass::factory()->create(['game_id' => $game->id, 'team_id' => $game->away_team_id, 'player_id' => $away->id, ...($sport === 'mlb' ? ['stat_type' => 'batting'] : [])]);
    array_pop($payload['boxscore']['players']);

    expect(app($actionClass)->execute($payload, $game))->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and($other->fresh())->not->toBeNull();
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('resolves and invokes configured sync collaborators for queued game detail jobs', function (string $sport) {
    $actionClass = "App\\Actions\\ESPN\\{$sport}\\SyncGameDetails";
    $jobClass = "App\\Jobs\\ESPN\\{$sport}\\FetchGameDetails";
    expect(app($actionClass))->toBeInstanceOf($actionClass);
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('execute')->once()->with('event-123')->andReturn(['plays' => 2, 'player_stats' => 2, 'team_stats' => 2]);
    app()->instance($actionClass, $action);
    $job = new $jobClass('event-123');
    expect($job->uniqueId())->toBe('event-123');
    app()->call([$job, 'handle']);
})->with(['NFL', 'NBA', 'CBB', 'WCBB', 'WNBA', 'MLB', 'CFB']);

it('rolls back staged play replacement on a later write failure', function (string $sport) {
    $gameClass = "App\\Models\\{$sport}\\Game";
    $playClass = "App\\Models\\{$sport}\\Play";
    $serviceClass = "App\\Services\\ESPN\\{$sport}\\EspnService";
    $actionClass = "App\\Actions\\ESPN\\{$sport}\\SyncPlays";
    $teamClass = "App\\Models\\{$sport}\\Team";
    $game = $gameClass::factory()->create(['status' => 'STATUS_IN_PROGRESS', 'home_team_id' => $teamClass::factory()->create()->id, 'away_team_id' => $teamClass::factory()->create()->id]);
    $old = $sport === 'MLB'
        ? $playClass::create(['game_id' => $game->id, 'espn_play_id' => 'old-play', 'sequence_number' => 1, 'inning' => 1, 'inning_half' => 'top', 'play_text' => 'Stored play'])
        : $playClass::factory()->create(['game_id' => $game->id]);
    $plays = [
        ['id' => 'new-1', 'text' => 'First play', 'period' => ['number' => 1]],
        ['id' => 'new-2', 'text' => 'Second play', 'period' => ['number' => 1]],
    ];
    $service = Mockery::mock($serviceClass);
    $service->shouldReceive('getGame')->andReturn(['competitions' => [['id' => $game->espn_event_id]], 'plays' => $plays]);
    $service->shouldReceive('getPlays')->andReturn(['items' => $plays]);
    $writes = 0;
    $fail = true;
    $playClass::creating(function () use (&$writes, &$fail) {
        if ($fail && ++$writes === 2) {
            throw new RuntimeException('Injected play failure');
        }
    });
    try {
        expect(fn () => (new $actionClass($service))->execute($game->espn_event_id))->toThrow(RuntimeException::class, 'Injected play failure');
    } finally {
        $fail = false;
    }
    expect($old->fresh())->not->toBeNull()
        ->and($playClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['NFL', 'NBA', 'CBB', 'WCBB', 'WNBA', 'MLB']);

it('preserves stored plays when malformed data is mixed into a replacement', function (string $sport) {
    $gameClass = "App\\Models\\{$sport}\\Game";
    $playClass = "App\\Models\\{$sport}\\Play";
    $serviceClass = "App\\Services\\ESPN\\{$sport}\\EspnService";
    $actionClass = "App\\Actions\\ESPN\\{$sport}\\SyncPlays";
    $teamClass = "App\\Models\\{$sport}\\Team";
    $game = $gameClass::factory()->create(['status' => 'STATUS_IN_PROGRESS', 'home_team_id' => $teamClass::factory()->create()->id, 'away_team_id' => $teamClass::factory()->create()->id]);
    $old = $sport === 'MLB'
        ? $playClass::create(['game_id' => $game->id, 'espn_play_id' => 'old-play', 'sequence_number' => 1, 'inning' => 1, 'inning_half' => 'top', 'play_text' => 'Stored play'])
        : $playClass::factory()->create(['game_id' => $game->id]);
    $plays = [['id' => 'new-1', 'period' => ['number' => 1]], ['text' => 'Missing identity']];
    $service = Mockery::mock($serviceClass);
    $service->shouldReceive('getGame')->andReturn(['competitions' => [['id' => $game->espn_event_id]], 'plays' => $plays]);
    $service->shouldReceive('getPlays')->andReturn(['items' => $plays]);

    expect((new $actionClass($service))->execute($game->espn_event_id))->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and($playClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['NFL', 'NBA', 'CBB', 'WCBB', 'WNBA', 'MLB']);

it('batches MLB play team identities across the entire payload', function () {
    $home = Team::factory()->create(['espn_id' => '501']);
    $away = Team::factory()->create(['espn_id' => '502']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $plays = collect(range(1, 12))->map(fn ($i) => ['id' => (string) $i, 'text' => 'Pitch', 'team' => ['id' => '501'], 'pitchingTeam' => ['id' => '502']])->all();
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->andReturn(['plays' => $plays]);
    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"mlb_teams"')) {
            $reads++;
        }
    });

    expect((new SyncPlays($service))->execute($game->espn_event_id))->toBe(12)
        ->and($reads)->toBe(1)
        ->and(Play::where('game_id', $game->id)->where('batting_team_id', $home->id)->where('pitching_team_id', $away->id)->count())->toBe(12);
});

it('merges NFL athlete categories before one event-aware observation write', function () {
    [$game, $statClass, $actionClass, $old, $payload] = replacementFixture('nfl');
    $payload['boxscore']['players'][0]['statistics'][] = ['name' => 'rushing', 'labels' => ['CAR', 'YDS', 'TD'], 'athletes' => [['athlete' => ['id' => '201'], 'stats' => ['3', '12', '1']]]];
    expect(app($actionClass)->execute($payload, $game))->toBe(3);
    $row = $statClass::where('game_id', $game->id)->where('team_id', $game->home_team_id)->firstOrFail();
    expect($row->passing_yards)->toBe(20)
        ->and($row->rushing_yards)->toBe(12)
        ->and($statClass::where('game_id', $game->id)->count())->toBe(2);
});

function teamStatReplacementFixture(string $sport): array
{
    [$game, , , , $playersPayload] = replacementFixture($sport);
    $namespace = strtoupper($sport);
    $statClass = "App\\Models\\{$namespace}\\TeamStat";
    $actionClass = "App\\Actions\\ESPN\\{$namespace}\\SyncTeamStats";
    $old = $statClass::factory()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'team_type' => 'home']);
    $statistics = match ($sport) {
        'mlb' => [['name' => 'batting', 'stats' => [['name' => 'runs', 'displayValue' => '4']]]],
        'nfl' => [['name' => 'totalYards', 'displayValue' => '350']],
        default => [['name' => 'points', 'displayValue' => '81']],
    };
    $teams = collect($playersPayload['boxscore']['players'])->map(fn ($section, $index) => ['team' => $section['team'], 'homeAway' => $index === 0 ? 'home' : 'away', 'statistics' => $statistics])->all();

    return [$game, $statClass, $actionClass, $old, ['boxscore' => ['teams' => $teams]]];
}

it('stages every team section before replacing team observations', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = teamStatReplacementFixture($sport);
    $payload['boxscore']['teams'][1]['statistics'] = [];

    expect(app($actionClass)->execute($payload, $game))->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('rolls back all team observations after a later model write fails', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = teamStatReplacementFixture($sport);
    $writes = 0;
    $fail = true;
    $statClass::creating(function () use (&$writes, &$fail) {
        if ($fail && ++$writes === 2) {
            throw new RuntimeException('Injected team observation failure');
        }
    });
    try {
        expect(fn () => app($actionClass)->execute($payload, $game))->toThrow(RuntimeException::class, 'Injected team observation failure');
    } finally {
        $fail = false;
    }

    expect($old->fresh())->not->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('replaces valid team observations with model events and one identity read', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = teamStatReplacementFixture($sport);
    $table = $sport.'_teams';
    $reads = 0;
    $events = 0;
    DB::listen(function ($query) use (&$reads, $table) {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"'.$table.'"')) {
            $reads++;
        }
    });
    $statClass::created(function () use (&$events) {
        $events++;
    });

    expect(app($actionClass)->execute($payload, $game))->toBe(2)
        ->and($events)->toBe(2)
        ->and($reads)->toBe(1)
        ->and($old->fresh())->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(2);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);

it('preserves prior team observations when the payload has no recognized statistics', function (string $sport) {
    [$game, $statClass, $actionClass, $old, $payload] = teamStatReplacementFixture($sport);
    $payload['boxscore']['teams'][1]['statistics'] = $sport === 'mlb'
        ? [['name' => 'unknown', 'stats' => [['name' => 'unknown', 'displayValue' => '4']]]]
        : [['name' => 'unknown', 'displayValue' => '4']];

    expect(app($actionClass)->execute($payload, $game))->toBe(0)
        ->and($old->fresh())->not->toBeNull()
        ->and($statClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'nfl', 'mlb']);
