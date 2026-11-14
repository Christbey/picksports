<?php

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\SportEvent;
use App\Services\Api\V2\PredictionModelBetContext;

it('exposes paired pregame market lines without requiring an approved bet', function (
    string $sport, string $gameClass, string $teamClass, array $homeFields, array $awayFields, string $homeName, string $awayName,
) {
    $home = $teamClass::factory()->create($homeFields);
    $away = $teamClass::factory()->create($awayFields);
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $prediction = new CanonicalPrediction(['sport' => $sport]);
    $prediction->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addHours(3)]));
    $snapshot = GameOddsSnapshot::create(['sport' => $sport, 'game_table' => $game->getTable(), 'game_id' => $game->id,
        'source' => 'odds_api', 'bookmaker_key' => 'test', 'captured_at' => now()->subMinutes(5), 'payload_hash' => hash('sha256', 'context'), 'odds_data' => []]);
    foreach ([['spreads', 'home', -13, $homeName], ['spreads', 'away', 13, $awayName], ['totals', 'over', 54.5, null], ['totals', 'under', 54.5, null]] as [$market, $side, $line, $participant]) {
        MarketQuote::create(['game_odds_snapshot_id' => $snapshot->id, 'sport' => $sport, 'game_table' => $game->getTable(), 'game_id' => $game->id,
            'source' => 'odds_api', 'bookmaker_key' => 'test', 'market_key' => $market, 'side' => $side, 'line' => $line, 'participant' => $participant,
            'captured_at' => now()->subMinutes(5), 'is_pregame' => true, 'quote_hash' => hash('sha256', $market.$side)]);
    }
    $service = app(PredictionModelBetContext::class);
    expect($service->forPrediction($prediction, $game))->toMatchArray(['home_spread' => -13.0, 'total' => 54.5]);
    MarketQuote::where('side', 'home')->update(['participant' => 'Wrong Team']);
    expect($service->forPrediction($prediction, $game))->toMatchArray(['home_spread' => null, 'total' => null]);
})->with([
    'CFB school and mascot' => [
        'cfb', Game::class, Team::class,
        ['school' => 'Home', 'mascot' => 'Team'], ['school' => 'Away', 'mascot' => 'Team'],
        'Home Team', 'Away Team',
    ],
    'NBA location and name' => [
        'nba', NbaGame::class, NbaTeam::class,
        ['location' => 'Minnesota', 'name' => 'Timberwolves'], ['location' => 'Chicago', 'name' => 'Bulls'],
        'Minnesota Timberwolves', 'Chicago Bulls',
    ],
]);
