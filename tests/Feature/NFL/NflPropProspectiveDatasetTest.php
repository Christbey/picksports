<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\NflPropMissingActualAudit;
use App\Services\NFL\NflPropProbabilityExperiment;
use App\Services\NFL\NflPropProspectiveDataset;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-11-25 20:00:00');
    $team = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $team->id]);
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026,
        'starts_at' => CarbonImmutable::parse('2026-11-22T18:00:00Z')->setTimezone(config('app.timezone'))]);
    $this->game = Game::factory()->create(['season' => 2026, 'season_type' => 2, 'week' => 11,
        'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id,
        'sport_event_id' => $event->id, 'status' => 'STATUS_FINAL']);
    $features = ['fingerprint_version' => 'round8-v1', 'kickoff_at' => '2026-11-22T18:00:00Z'];
    $outputs = ['over_probability' => 0.6, 'push_probability' => 0.0];
    $market = ['market' => 'player_receptions', 'line' => 4.5, 'over_price' => -110, 'under_price' => -110,
        'quote_observed_at' => '2026-11-21T18:00:00Z'];
    $this->snapshot = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_player_props',
        'prediction_id' => 1, 'game_id' => $this->game->id, 'snapshot_run_id' => (string) Str::uuid(),
        'model_version' => 'shadow', 'feature_version' => 'code', 'blend_version' => NflPropProbabilityExperiment::POLICY,
        'features' => $features, 'outputs' => $outputs, 'market_context' => $market,
        'model_metadata' => ['player_id' => $this->player->id, 'team_id' => $team->id],
        'feature_hash' => NflPropProbabilityExperiment::fingerprint($features, $outputs, $market),
        'generated_at' => '2026-11-21T13:00:00-06:00', 'features_available_at' => '2026-11-21T13:00:00-06:00',
        'game_start_at' => '2026-11-22T12:00:00-06:00', 'pregame_safe' => true]);
});

it('classifies missing rows missing fields and genuine zero without creating labels', function () {
    $service = new NflPropProspectiveDataset;
    expect($service->build(2026)['exclusions'])->toBe([]);
    expect($service->build(2026)['pending']['player_receptions']['missing_player_stat'])->toBe(1);
    $stat = PlayerStat::factory()->create(['player_id' => $this->player->id, 'team_id' => $this->player->team_id,
        'game_id' => $this->game->id, 'receptions' => null]);
    expect($service->build(2026)['pending']['player_receptions']['missing_market_actual_or_participation'])->toBe(1);
    $stat->update(['receptions' => 0]);
    expect($service->build(2026)['pending']['player_receptions']['actual_available_not_evaluated'])->toBe(1)
        ->and(PredictionEvaluation::count())->toBe(0);
});

it('accepts a verified zero label but excludes a subsequent stat discrepancy without rewriting evidence', function () {
    $stat = PlayerStat::factory()->create(['player_id' => $this->player->id, 'team_id' => $this->player->team_id,
        'game_id' => $this->game->id, 'receptions' => 0]);
    $label = PredictionEvaluation::create(['sport' => 'nfl', 'prediction_table' => 'prediction_feature_snapshots',
        'prediction_id' => $this->snapshot->id, 'game_id' => $this->game->id, 'model_version' => 'shadow',
        'feature_version' => 'code', 'blend_version' => NflPropProbabilityExperiment::POLICY,
        'actuals' => ['value' => 0, 'stat_id' => $stat->id], 'errors' => [], 'market_comparison' => ['push' => false], 'evaluated_at' => now()]);
    $service = new NflPropProspectiveDataset;
    expect($service->build(2026)['rows'])->toHaveCount(1);
    $stat->update(['receptions' => 1]);
    $report = $service->build(2026);
    expect($report['rows'])->toBeEmpty()->and($report['exclusions']['current_stat_differs_from_label'])->toBe(1)
        ->and($label->fresh()->actuals['value'])->toBe(0);
});

it('reports absent source coverage without claiming participation or repairing values', function () {
    $report = app(NflPropMissingActualAudit::class)->report(2026);
    expect($report['unique_player_games'])->toBe(1)
        ->and($report['markets']['player_receptions']['pending']['player_stat_row_missing'])->toBe(1)
        ->and($report['markets']['player_receptions']['boxscore_source']['boxscore_archive_missing'])->toBe(1)
        ->and($report['production_modified'])->toBeFalse()
        ->and(PlayerStat::count())->toBe(0)->and(PredictionEvaluation::count())->toBe(0);
});
