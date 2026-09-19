<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\CFB\Signals\CfbFootballSignalHistoricalTrainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

it('trains from prior-season ratings without letting target-season final metrics alter historical residuals', function () {
    CarbonImmutable::setTestNow('2026-09-19');
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach ([$home, $away] as $team) {
        TeamMetric::create(['team_id' => $team->id, 'season' => 2024, 'wins' => 8, 'losses' => 4,
            'fpi' => 5, 'points_per_game' => 28, 'points_allowed_per_game' => 21]);
    }
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => '2025-09-01 12:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2025, 'game_date' => '2025-09-01',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL',
        'home_score' => 35, 'away_score' => 14, 'neutral_site' => false]);
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    unset($config['football_signals']['weighting']);
    $config['football_signals']['catalog'] = ['home' => ['id' => 'home', 'market' => 'spread',
        'condition' => ['all' => [['field' => 'context.home', 'operator' => '==', 'value' => true]]]]];
    $trainer = app(CfbFootballSignalHistoricalTrainer::class);
    $first = $trainer->train($config, 2025, 2025, CarbonImmutable::now());
    TeamMetric::create(['team_id' => $home->id, 'season' => 2025, 'wins' => 12, 'losses' => 0,
        'fpi' => 99, 'points_per_game' => 99, 'points_allowed_per_game' => 0]);
    $second = $trainer->train($config, 2025, 2025, CarbonImmutable::now());
    expect($first['source_game_ids'])->toBe([$game->id])
        ->and($second['observations'])->toBe($first['observations'])
        ->and($first['observations']['home'][$game->id]['residual'])->toBe(19.0)
        ->and($first['historical_availability_proven'])->toBeFalse();
    $evidence = app(CfbFootballSignalEvidence::class)->build(CarbonImmutable::now(), $config);
    expect($evidence['signals']['home']['sample_games'])->toBe(1)
        ->and($evidence['historical_training']['source_game_ids'])->toBe([$game->id]);
    $artifact = Cache::get(CfbFootballSignalHistoricalTrainer::key($config));
    $artifact['available_at'] = CarbonImmutable::now()->addDay()->toIso8601String();
    Cache::put(CfbFootballSignalHistoricalTrainer::key($config), $artifact);
    expect(app(CfbFootballSignalEvidence::class)->build(CarbonImmutable::now(), $config)['historical_training'])->toBeNull();
    CarbonImmutable::setTestNow();
});
