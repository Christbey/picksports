<?php

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\SportEvent;
use App\Services\Api\V2\PredictionModelBetContext;

it('exposes paired pregame market lines without requiring an approved bet', function () {
    $home = Team::factory()->create(['school' => 'Home', 'mascot' => 'Team']);
    $away = Team::factory()->create(['school' => 'Away', 'mascot' => 'Team']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $prediction = new CanonicalPrediction(['sport' => 'cfb']);
    $prediction->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addHours(3)]));
    $snapshot = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'odds_api', 'bookmaker_key' => 'test', 'captured_at' => now()->subMinutes(5), 'payload_hash' => hash('sha256', 'context'), 'odds_data' => []]);
    foreach ([['spreads', 'home', -13, 'Home Team'], ['spreads', 'away', 13, 'Away Team'], ['totals', 'over', 54.5, null], ['totals', 'under', 54.5, null]] as [$market, $side, $line, $participant]) {
        MarketQuote::create(['game_odds_snapshot_id' => $snapshot->id, 'sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
            'source' => 'odds_api', 'bookmaker_key' => 'test', 'market_key' => $market, 'side' => $side, 'line' => $line, 'participant' => $participant,
            'captured_at' => now()->subMinutes(5), 'is_pregame' => true, 'quote_hash' => hash('sha256', $market.$side)]);
    }
    $service = app(PredictionModelBetContext::class);
    expect($service->forPrediction($prediction, $game))->toMatchArray(['home_spread' => -13.0, 'total' => 54.5]);
    MarketQuote::where('side', 'home')->update(['participant' => 'Wrong Team']);
    expect($service->forPrediction($prediction, $game))->toMatchArray(['home_spread' => null, 'total' => null]);
});
