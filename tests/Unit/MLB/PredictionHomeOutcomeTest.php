<?php

use App\Actions\MLB\GeneratePrediction;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

it('selects only an identified home run line', function (?string $location, ?string $name, ?string $metadata, ?float $expected, float $homeLine) {
    $team = new Team;
    $team->setRawAttributes(['id' => 1, 'location' => $location, 'name' => $name]);
    $game = new Game;
    $game->setRawAttributes(['id' => 1]);
    $game->setRelation('homeTeam', $team);
    $odds = ['bookmakers' => [['markets' => [['key' => 'spreads', 'outcomes' => [
        ['name' => 'Away Bears', 'point' => 1.5],
        ['name' => 'Home Owls', 'point' => $homeLine],
    ]]]]]];
    if ($metadata !== null) {
        $odds['home_team'] = $metadata;
    }
    $class = new ReflectionClass(GeneratePrediction::class);
    $action = $class->newInstanceWithoutConstructor();
    $actual = $class->getMethod('getVegasSpread')->invoke($action, $game, $odds);
    expect($actual)->toBe($expected);
})->with([
    'missing location' => [null, 'Owls', null, -1.5, -1.5],
    'missing name' => ['Home', null, null, -1.5, -1.5],
    'both missing' => [null, null, null, null, -1.5],
    'blank identity' => ['', '', null, null, -1.5],
    'space location' => [' ', 'Owls', null, -1.5, -1.5],
    'space name' => ['Home', ' ', null, -1.5, -1.5],
    'both spaces' => [' ', ' ', null, null, -1.5],
    'complete identity' => ['Home', 'Owls', null, -1.5, -1.5],
    'real zero' => ['Home', 'Owls', null, 0.0, 0.0],
    'explicit metadata' => [null, null, 'Home Owls', -1.5, -1.5],
]);
