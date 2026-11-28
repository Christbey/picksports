<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\MLB\Picks\MlbPickMarketService;

function picksIdentityGame(array $outcomes, bool $sharedNickname = false): Game
{
    $game = new Game(['odds_data' => ['bookmakers' => [['key' => 'synthetic', 'markets' => [['key' => 'h2h', 'outcomes' => $outcomes]]]]]]);
    $game->setRelation('homeTeam', new Team(['display_name' => 'Home Comets', 'location' => 'Home', 'name' => $sharedNickname ? 'Birds' : 'Comets', 'abbreviation' => 'HME']));
    $game->setRelation('awayTeam', new Team(['display_name' => 'Away Stars', 'location' => 'Away', 'name' => $sharedNickname ? 'Birds' : 'Stars', 'abbreviation' => 'AWY']));

    return $game;
}

it('does not bind a nameless quote to either team', function () {
    expect(app(MlbPickMarketService::class)->sideOutcomes(picksIdentityGame([['price' => -110]]), ['h2h']))->toBe(['home' => null, 'away' => null]);
});

it('does not bind a shared nickname quote to both teams', function () {
    expect(app(MlbPickMarketService::class)->sideOutcomes(picksIdentityGame([['name' => 'Birds', 'price' => -110]], true), ['h2h']))->toBe(['home' => null, 'away' => null]);
});

it('preserves an exact partial side without inventing a no-vig pair', function () {
    $service = app(MlbPickMarketService::class);
    $outcomes = $service->sideOutcomes(picksIdentityGame([['name' => 'Home Comets', 'price' => -110]]), ['h2h']);
    expect($outcomes['home']['price'])->toBe(-110)->and($outcomes['away'])->toBeNull();
    expect($service->noVigSides($outcomes))->toBe(['home' => null, 'away' => null]);
});
