<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('preserves basketball stat fields without loading missing relationships', function (string $sport) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $attributes = [
        'id' => 7, 'team_id' => 11, 'game_id' => 19, 'team_type' => 'home',
        'field_goals_made' => 30, 'field_goals_attempted' => 70,
        'three_point_made' => 9, 'three_point_attempted' => 25,
        'free_throws_made' => 12, 'free_throws_attempted' => 16,
        'rebounds' => 40, 'offensive_rebounds' => 10, 'defensive_rebounds' => 30,
        'assists' => 20, 'turnovers' => 11, 'steals' => 6, 'blocks' => 4,
        'fouls' => 17, 'points' => 81, 'possessions' => 71.5,
        'fast_break_points' => 8, 'points_in_paint' => 32,
        'second_chance_points' => 6, 'bench_points' => 22, 'biggest_lead' => 12,
        'times_tied' => 3, 'lead_changes' => 5,
    ];
    $stat = (new $modelClass)->forceFill($attributes);

    expect((new $resourceClass($stat))->resolve(Request::create('/')))
        ->toBe([...$attributes, 'created_at' => null, 'updated_at' => null])
        ->and($stat->getRelations())->toBe([]);
})->with(['NBA', 'CBB', 'WCBB']);

it('uses each sports own nested resources for loaded relationships', function (string $sport) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $teamClass = "App\\Models\\{$sport}\\Team";
    $gameClass = "App\\Models\\{$sport}\\Game";
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $teamResource = "App\\Http\\Resources\\{$sport}\\TeamResource";
    $gameResource = "App\\Http\\Resources\\{$sport}\\GameResource";
    $stat = (new $modelClass)->setRelation('team', new $teamClass)->setRelation('game', new $gameClass);
    $stat->created_at = '2026-10-01 12:00:00';
    $stat->updated_at = '2026-10-02 13:00:00';
    $data = (new $resourceClass($stat))->toArray(Request::create('/'));

    expect($data['team'])->toBeInstanceOf($teamResource)
        ->and($data['game'])->toBeInstanceOf($gameResource)
        ->and($data['created_at'])->toBe($stat->created_at->toIso8601String())
        ->and($data['updated_at'])->toBe($stat->updated_at->toIso8601String());
})->with(['NBA', 'CBB', 'WCBB']);

it('preserves raw scalar types, nulls and zero values', function (string $sport) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $stat = (new $modelClass)->setRawAttributes([
        'id' => 7,
        'team_id' => 11,
        'game_id' => 19,
        'field_goals_made' => '0',
        'possessions' => '72.50',
        'points_in_paint' => null,
        'fast_break_points' => 0,
    ]);
    $data = (new $resourceClass($stat))->resolve(Request::create('/'));

    expect($data['field_goals_made'])->toBe('0')
        ->and($data['possessions'])->toBe($sport === 'NBA' ? 72.5 : '72.50')
        ->and($data['points_in_paint'])->toBeNull()
        ->and($data['fast_break_points'])->toBe(0)
        ->and($data)->not->toHaveKeys(['team', 'game']);
})->with(['NBA', 'CBB', 'WCBB']);

it('distinguishes unloaded relationships from explicitly loaded null relationships in JSON', function (string $sport) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $stat = (new $modelClass)->setRelation('team', null)->setRelation('game', null);
    $data = json_decode((new $resourceClass($stat))->toJson(), true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toHaveKeys(['team', 'game'])
        ->and($data['team'])->toBeNull()
        ->and($data['game'])->toBeNull()
        ->and($data['created_at'])->toBeNull()
        ->and($data['updated_at'])->toBeNull();
})->with(['NBA', 'CBB', 'WCBB']);

it('serializes only the requested nested relationship without extra queries', function (string $sport, string $relation) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $nestedModelClass = 'App\\Models\\'.$sport.'\\'.ucfirst($relation);
    $nestedResourceClass = 'App\\Http\\Resources\\'.$sport.'\\'.ucfirst($relation).'Resource';
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $nested = (new $nestedModelClass)->forceFill([
        'id' => 19,
        'name' => 'Fixture Team',
        'school' => 'Fixture School',
        'mascot' => 'Fixture Mascot',
        'game_date' => '2026-10-01',
        'game_time' => '19:00:00',
        'home_score' => 0,
    ]);
    $stat = (new $modelClass)->forceFill(['id' => 7, 'team_id' => 11, 'game_id' => 19])->setRelation($relation, $nested);
    $connection = DB::connection();
    $connection->enableQueryLog();

    $data = json_decode((new $resourceClass($stat))->toJson(), true, flags: JSON_THROW_ON_ERROR);
    $expected = json_decode((new $nestedResourceClass($nested))->toJson(), true, flags: JSON_THROW_ON_ERROR);

    expect($data[$relation])->toBe($expected)
        ->and($data)->not->toHaveKey($relation === 'team' ? 'game' : 'team')
        ->and(array_keys($stat->getRelations()))->toBe([$relation])
        ->and($connection->getQueryLog())->toBe([]);
})->with(['NBA', 'CBB', 'WCBB'])->with(['team', 'game']);

it('preserves collection envelopes and nested game stat serialization', function (string $sport) {
    $modelClass = "App\\Models\\{$sport}\\TeamStat";
    $gameClass = "App\\Models\\{$sport}\\Game";
    $resourceClass = "App\\Http\\Resources\\{$sport}\\TeamStatResource";
    $gameResourceClass = "App\\Http\\Resources\\{$sport}\\GameResource";
    $stat = (new $modelClass)->forceFill(['id' => 7, 'team_id' => 11, 'game_id' => 19, 'points' => 0]);
    $stat->created_at = '2026-10-01 12:00:00';
    $stat->updated_at = '2026-10-02 13:00:00';
    $request = Request::create('/');
    $expected = json_decode((new $resourceClass($stat))->toJson(), true, flags: JSON_THROW_ON_ERROR);
    $collection = $resourceClass::collection(collect([$stat]))->response($request)->getData(true);
    $game = (new $gameClass)->forceFill(['id' => 19, 'game_date' => '2026-10-01', 'game_time' => '19:00:00'])->setRelation('teamStats', new Collection([$stat]));
    $gameData = json_decode((new $gameResourceClass($game))->toJson(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($collection))->toBe(['data'])
        ->and($collection['data'])->toBe([$expected])
        ->and($gameData['team_stats'])->toBe([$expected])
        ->and($expected['points'])->toBe(0)
        ->and($expected['created_at'])->toBe($stat->created_at->toIso8601String())
        ->and($expected['updated_at'])->toBe($stat->updated_at->toIso8601String());
})->with(['NBA', 'CBB', 'WCBB']);
