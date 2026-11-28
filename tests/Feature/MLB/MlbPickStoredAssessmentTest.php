<?php

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Services\Api\V2\MlbDailyPickPageData;
use App\Services\MLB\Picks\MlbPickMarketService;
use App\Services\MLB\Picks\MlbTotalCandidateBuilder;
use App\Services\Predictions\BettingForecastSource;

it('leaves unassessed stored candidates unknown and never reports a known blocked assessment safe', function () {
    $page = app(MlbDailyPickPageData::class);
    $slate = ['slate_games' => 1, 'priced_games' => 1, 'first_inning_priced_games' => 0, 'first_3_priced_games' => 0, 'first_5_priced_games' => 0];
    $unknown = new PickCandidate(['risk_flags' => [], 'feature_snapshot' => []]);
    expect($page->summary(collect([$unknown]), collect(), $slate)['pregame_safe_rate'])->toBeNull();
    $blocked = new PickCandidate(['risk_flags' => ['missing_game_start_time', 'pregame_signal_safety_block'], 'feature_snapshot' => ['signal_layer' => ['version' => 'mlb_signal_driver_v1', 'pregame_safe' => false]]]);
    expect($page->summary(collect([$blocked]), collect(), $slate)['pregame_safe_rate'])->toBe(0.0);
    $safe = new PickCandidate(['risk_flags' => [], 'feature_snapshot' => ['signal_layer' => ['version' => 'mlb_signal_driver_v1', 'pregame_safe' => true]]]);
    expect($page->summary(collect([$safe]), collect(), $slate)['pregame_safe_rate'])->toBe(1.0);
    expect($page->summary(collect([$safe, $unknown]), collect(), $slate)['pregame_safe_rate'])->toBeNull();
});

it('does not award directional weather or park support merely because a weather row exists', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    GameWeather::create(['game_id' => $game->id, 'observed_at' => now(), 'provider' => 'synthetic']);
    $markets = Mockery::mock(MlbPickMarketService::class);
    $markets->shouldReceive('totalOutcomes')->andReturn(['over' => ['line' => 8.5, 'price' => -110], 'under' => ['line' => 8.5, 'price' => -110]]);
    $markets->shouldReceive('noVigTotals')->andReturn(['over' => 0.5, 'under' => 0.5]);
    $markets->shouldReceive('implied')->andReturn(0.52);
    $markets->shouldReceive('stale')->andReturn(false);
    $builder = new MlbTotalCandidateBuilder($markets, app(BettingForecastSource::class));
    $forecast = fn (array $metadata) => new BettingForecast(new Prediction(['season' => 2026, 'game_id' => $game->id]), $game, predictedTotal: 9.0, modelMetadata: $metadata);
    foreach ($builder->build($forecast([])) as $row) {
        expect(array_intersect($row->reasonCodes, ['weather_supports_over', 'weather_supports_under', 'park_support']))->toBe([]);
    }
    $rows = $builder->build($forecast(['actual_weather' => ['applied' => true, 'total_adjustment' => 0.2], 'park_context' => ['total_adjustment' => -0.3]]));
    expect($rows[0]->reasonCodes)->toContain('weather_supports_over')->not->toContain('park_support');
    expect($rows[1]->reasonCodes)->not->toContain('weather_supports_under')->toContain('park_support');
    foreach ($builder->build($forecast(['actual_weather' => ['applied' => true, 'total_adjustment' => 0], 'park_context' => ['total_adjustment' => '1e999']])) as $row) {
        expect(array_intersect($row->reasonCodes, ['weather_supports_over', 'weather_supports_under', 'park_support']))->toBe([]);
    }
    $foreign = $forecast(['actual_weather' => ['applied' => true, 'total_adjustment' => 0.2], 'park_context' => ['total_adjustment' => 0.3]]);
    $foreign->source->game_id = $game->id + 100;
    foreach ($builder->build($foreign) as $row) {
        expect(array_intersect($row->reasonCodes, ['weather_supports_over', 'weather_supports_under', 'park_support']))->toBe([]);
    }
});
