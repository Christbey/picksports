<?php

use App\Services\Admin\HealthcheckRemediationCatalog;
use App\Services\Sports\SportsPipelineRegistry;

it('admits each reviewed recommendation form with bounded typed arguments', function (string $sport, string $check, string $text, string $command, array $arguments) {
    $result = (new HealthcheckRemediationCatalog(new SportsPipelineRegistry))->resolve($sport, $check, $text);
    expect($result)->not->toBeNull()->and($result['command'])->toBe($command)->and($result['arguments'])->toBe($arguments)->and($result['effects'])->not->toBeEmpty();
})->with([
    ['nba', 'validation_prediction_completeness', 'nba:generate-predictions', 'nba:generate-predictions', []],
    ['nfl', 'validation_prediction_completeness', 'nfl:generate-canonical-predictions', 'nfl:generate-canonical-predictions', []],
    ['mlb', 'validation_current_day_game_data_freshness', 'espn:sync-mlb-game-details', 'espn:sync-mlb-game-details', []],
    ['wnba', 'validation_team_stat_coverage', 'espn:sync-wnba-game-details', 'espn:sync-wnba-game-details', []],
    ['cbb', 'validation_finalized_data_completeness', 'espn:sync-cbb-game-details', 'espn:sync-cbb-game-details', []],
    ['nba', 'validation_odds_completeness', 'nba:sync-odds', 'nba:sync-odds', []],
    ['wcbb', 'validation_injury_freshness', 'espn:sync-wcbb-injuries', 'espn:sync-wcbb-injuries', []],
    ['nfl', 'validation_live_prediction_freshness', 'espn:sync-nfl-games-scoreboard', 'espn:sync-nfl-games-scoreboard', []],
    ['nba', 'validation_past_scheduled_game_status', 'espn:sync-nba-games-scoreboard --from-date=2026-11-01 --to-date=2026-11-08', 'espn:sync-nba-games-scoreboard', ['--from-date' => '2026-11-01', '--to-date' => '2026-11-08']],
    ['cfb', 'validation_schedule_window_integrity', 'sports:operations-sentinel --sport=cfb', 'sports:operations-sentinel', ['--sport' => 'cfb']],
    ['nfl', 'validation_upcoming_game_readiness', 'sports:operations-sentinel --sport=nfl', 'sports:operations-sentinel', ['--sport' => 'nfl']],
    ['mlb', 'validation_futures_odds_freshness', 'sports:sync-futures-odds --sport=mlb --season=2026', 'sports:sync-futures-odds', ['--sport' => 'mlb', '--season' => 2026]],
    ['nba', 'validation_player_prop_freshness', 'sports:analyze-player-props --sport=nba --season=2026 --only-missing', 'sports:analyze-player-props', ['--sport' => 'nba', '--season' => 2026, '--only-missing' => true]],
    ['nba', 'validation_player_prop_freshness', 'sports:analyze-player-props --sport=nba', 'sports:analyze-player-props', ['--sport' => 'nba']],
    ['nfl', 'validation_nfl_research_coverage', 'nfl:research-pipeline --days-forward=3 --limit=4', 'nfl:research-pipeline', ['--days-forward' => 3, '--limit' => 4]],
    ['cfb', 'validation_prediction_completeness', 'cfb:run-pregame-pipeline --days-forward=8 --resume', 'cfb:run-pregame-pipeline', ['--days-forward' => 8, '--resume' => true]],
    ['nfl', 'validation_pipeline_order', 'nfl:run-pregame-pipeline', 'nfl:run-pregame-pipeline', []],
    ['cfb', 'validation_pipeline_order', 'cfb:import-fpi', 'cfb:import-fpi', []],
    ['cfb', 'validation_pipeline_order', 'cfb:calculate-team-metrics', 'cfb:calculate-team-metrics', []],
    ['cbb', 'validation_pipeline_order', 'cbb:sync-player-props', 'cbb:sync-player-props', []],
    ['mlb', 'validation_weather_completeness', 'mlb:sync-game-weather --days-back=0 --days-forward=7 --force', 'mlb:sync-game-weather', ['--days-back' => 0, '--days-forward' => 7, '--force' => true]],
    ['nfl', 'validation_weather_completeness', 'nfl:sync-game-weather --days-back=0 --days-forward=7 --force', 'nfl:sync-game-weather', ['--days-back' => 0, '--days-forward' => 7, '--force' => true]],
    ['mlb', 'validation_pipeline_order', 'sports:ai-daily-predictions --sport=mlb', 'sports:ai-daily-predictions', ['--sport' => 'mlb']],
    ['cfb', 'validation_pipeline_order', 'sports:ai-daily-predictions --sport=cfb', 'sports:ai-daily-predictions', ['--sport' => 'cfb']],
]);

it('refuses unreviewed scope options malformed dates duplicate flags and controls', function (string $sport, string $check, mixed $text) {
    expect((new HealthcheckRemediationCatalog(new SportsPipelineRegistry))->resolve($sport, $check, $text))->toBeNull();
})->with([
    ['nba', 'validation_pipeline_order', 'migrate:fresh --force'],
    ['nba', 'validation_prediction_completeness', 'nfl:generate-predictions'],
    ['nba', 'validation_odds_completeness', 'nba:sync-odds --force'],
    ['nba', 'validation_odds_completeness', "nba:sync-odds\n"],
    ['nba', 'validation_odds_completeness', ['nba:sync-odds']],
    ['nfl', 'validation_nfl_research_coverage', 'nfl:research-pipeline --days-forward=3 --limit=4 --limit=4'],
    ['nfl', 'validation_nfl_research_coverage', 'nfl:research-pipeline --days-forward=15 --limit=4'],
    ['nfl', 'validation_nfl_research_coverage', 'nfl:research-pipeline --days-forward=3 --limit=100'],
    ['mlb', 'validation_futures_odds_freshness', 'sports:sync-futures-odds --sport=nfl --season=2026'],
    ['mlb', 'validation_futures_odds_freshness', 'sports:sync-futures-odds --sport=mlb --season=9999'],
    ['nba', 'validation_player_prop_freshness', 'sports:analyze-player-props --sport=nba --only-missing=false'],
    ['nba', 'validation_past_scheduled_game_status', 'espn:sync-nba-games-scoreboard --from-date=2026-02-30 --to-date=2026-03-01'],
    ['nba', 'validation_past_scheduled_game_status', 'espn:sync-nba-games-scoreboard --from-date=2026-11-08 --to-date=2026-11-01'],
    ['nba', 'validation_past_scheduled_game_status', 'espn:sync-nba-games-scoreboard --from-date=2026-01-01 --to-date=2026-11-01'],
    ['nba', 'validation_past_scheduled_game_status', 'espn:sync-nba-games-scoreboard --from-date=2026-11-01'],
    ['nba', 'validation_prediction_completeness', str_repeat('x', 513)],
    ['unsupported', 'validation_odds_completeness', 'unsupported:sync-odds'],
    ['nba', 'validation_unknown', 'nba:sync-odds'],
]);
