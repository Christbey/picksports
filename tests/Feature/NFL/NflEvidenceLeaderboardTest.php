<?php

use App\Models\ModelRun;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\User;
use App\Services\NFL\NflEvidenceLeaderboard;
use App\Services\NFL\NflMonitorReportCache;
use App\Services\NFL\NflSignalGradeReportService;
use App\Services\NFL\NflSignalGradingService;
use App\Services\NFL\NflSignalObservationMaterializer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function evidenceSnapshot(array $overrides = []): PredictionFeatureSnapshot
{
    $season = (int) ($overrides['season'] ?? 2024);
    $homeScore = (int) ($overrides['home_score'] ?? 27);
    $awayScore = (int) ($overrides['away_score'] ?? 20);
    $home = Team::where('abbreviation', 'PHI')->first() ?? Team::factory()->create(['abbreviation' => 'PHI']);
    $away = Team::where('abbreviation', 'DAL')->first() ?? Team::factory()->create(['abbreviation' => 'DAL']);
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => $season,
        'season_type' => '2',
        'week' => $overrides['week'] ?? 8,
        'game_date' => "{$season}-10-20",
        'game_time' => '17:00:00',
        'status' => $overrides['status'] ?? 'STATUS_FINAL',
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => $overrides['predicted_spread'] ?? 4.0,
        'predicted_total' => $overrides['predicted_total'] ?? 44.0,
        'win_probability' => $overrides['win_probability'] ?? 0.65,
        'confidence_score' => 65,
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
    ]);
    $modelRun = ModelRun::query()->create([
        'id' => (string) Str::uuid(),
        'sport' => 'nfl',
        'run_type' => 'prediction',
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
        'config_hash' => hash('sha256', "config-{$season}"),
        'status' => 'completed',
        'started_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'completed_at' => Carbon::parse("{$season}-10-20 10:01:00"),
    ]);
    $metadata = $overrides['model_metadata'] ?? [
        'analysis_layer' => [
            'reason_codes' => ['qb_form_home_edge', 'market_total_edge_under'],
            'risk_flags' => ['low_model_confidence'],
            'reason_code_metadata' => [],
            'bet_rule_evaluation' => [
                'matched_rules' => [[
                    'name' => 'total_market_edge_play',
                    'label' => 'Total Market Edge Play',
                    'action' => 'play',
                    'market' => 'total',
                ]],
                'pass_rules' => ['pass_conflicting_or_low_quality'],
            ],
            'validated_signals' => [[
                'name' => 'qb_form_pressure_mismatch',
                'label' => 'QB Form + Pressure Mismatch',
                'market' => 'winner',
                'tier' => 'strong',
                'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
            ]],
            'calculated_edge' => [
                'market_spread' => $overrides['market_home_margin'] ?? 3.0,
                'market_total' => $overrides['market_total'] ?? 45.0,
            ],
        ],
    ];

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'nfl',
        'prediction_table' => 'nfl_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $modelRun->id,
        'model_version' => 'nfl-model-v1',
        'feature_version' => 'nfl-features-v1',
        'blend_version' => 'nfl-blend-v1',
        'features' => ['elo_difference' => 40],
        'outputs' => [
            'win_probability' => $overrides['win_probability'] ?? 0.65,
            'baseline_win_probability' => $overrides['baseline_win_probability'] ?? 0.55,
            'predicted_spread' => $overrides['predicted_spread'] ?? 4.0,
            'predicted_total' => $overrides['predicted_total'] ?? 44.0,
            'market_spread' => $overrides['market_home_margin'] ?? 3.0,
            'market_total' => $overrides['market_total'] ?? 45.0,
        ],
        'market_context' => [
            'market_home_margin' => $overrides['market_home_margin'] ?? 3.0,
            'market_total' => $overrides['market_total'] ?? 45.0,
        ],
        'model_metadata' => $metadata,
        'feature_hash' => hash('sha256', "features-{$season}-{$game->id}"),
        'generated_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'game_start_at' => Carbon::parse("{$season}-10-20 12:00:00"),
        'features_available_at' => Carbon::parse("{$season}-10-20 10:00:00"),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
        'source_timestamps' => ['odds' => "{$season}-10-20T10:00:00Z"],
        'lineage_metadata' => ['point_in_time_verified' => true],
        ...Arr::only($overrides, ['pregame_safe', 'availability_status', 'generated_at', 'features_available_at']),
    ]);
}

function evidenceGrade(PredictionFeatureSnapshot $snapshot): void
{
    app(NflSignalObservationMaterializer::class)->materialize($snapshot);
    foreach (NflSignalObservation::where('prediction_feature_snapshot_id', $snapshot->id)->get() as $observation) {
        app(NflSignalGradingService::class)->grade($observation);
    }
}

it('reuses outcome grades and isolates models, definitions and markets while deduplicating games', function () {
    $snapshot = evidenceSnapshot();
    evidenceGrade($snapshot);
    $copy = $snapshot->replicate();
    $copy->snapshot_run_id = (string) Str::uuid();
    $copy->generated_at = $snapshot->generated_at->addMinutes(10);
    $copy->save();
    evidenceGrade($copy);
    $filters = ['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1', 'signal_key' => 'qb_form_home_edge'];
    $report = app(NflSignalGradeReportService::class)->leaderboard($filters);
    expect($report['rows'])->toHaveCount(3)->and($report['selected_observations'])->toBe(1);
    $winner = collect($report['rows'])->firstWhere('market', 'winner');
    expect($winner['sample'])->toBe(1)->and($winner['wins'])->toBe(1)
        ->and($winner['wilson_lower'])->toBeLessThan(.3)
        ->and($winner['baseline_lift'])->toBeGreaterThan(0)
        ->and($winner['cohorts']['divisional']['sample'])->toBe(1)
        ->and($winner['status'])->toBe('insufficient_evidence')
        ->and($winner['validated'])->toBeFalse()->and($winner['bet_eligible'])->toBeFalse();
    $copy2 = $snapshot->replicate();
    $copy2->snapshot_run_id = (string) Str::uuid();
    $copy2->model_version = 'other-model';
    $copy2->save();
    evidenceGrade($copy2);
    $report = app(NflSignalGradeReportService::class)->leaderboard($filters);
    expect($report['rows'])->toHaveCount(3);
    $scoped = app(NflSignalGradeReportService::class)->leaderboard([...$filters, 'model_version' => 'other-model']);
    expect($scoped['rows'])->toHaveCount(3);
    $metadata = $copy2->model_metadata;
    $metadata['analysis_layer']['reason_code_metadata']['qb_form_home_edge'] = [
        'label' => 'Revised QB evidence', 'direction' => 'home', 'market_type' => 'winner',
    ];
    $copy3 = $copy2->replicate();
    $copy3->snapshot_run_id = (string) Str::uuid();
    $copy3->model_metadata = $metadata;
    $copy3->save();
    evidenceGrade($copy3);
    expect(app(NflSignalGradeReportService::class)->leaderboard([...$filters, 'model_version' => 'other-model'])['rows'])->toHaveCount(6);
});

it('excludes unsafe, reconstructed and kickoff mismatched evidence and keeps missing grades visible', function () {
    $snapshot = evidenceSnapshot();
    evidenceGrade($snapshot);
    $unsafe = evidenceSnapshot(['pregame_safe' => false]);
    evidenceGrade($unsafe);
    $reconstruction = evidenceSnapshot(['availability_status' => 'verified_reconstruction']);
    evidenceGrade($reconstruction);
    $late = evidenceSnapshot(['generated_at' => Carbon::parse('2024-10-20 13:00:00')]);
    evidenceGrade($late);
    $pending = evidenceSnapshot(['status' => 'STATUS_SCHEDULED']);
    app(NflSignalObservationMaterializer::class)->materialize($pending);
    $filters = ['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1', 'signal_key' => 'qb_form_home_edge'];
    $report = app(NflEvidenceLeaderboard::class)->report($filters);
    $winner = collect($report['rows'])->firstWhere('market', 'winner');
    expect($report['selected_observations'])->toBe(2)->and($winner['sample'])->toBe(1)
        ->and($winner['ungraded'])->toBe(1)->and($winner['games'])->toBe(2);
    DB::table('nfl_signal_observations')->where('prediction_feature_snapshot_id', $snapshot->id)
        ->update(['game_start_at' => '2024-10-20 11:00:00']);
    expect(app(NflEvidenceLeaderboard::class)->report($filters)['selected_observations'])->toBe(1);
});

it('separates discovery and later weeks without declaring validation', function () {
    evidenceGrade(evidenceSnapshot(['week' => 2]));
    evidenceGrade(evidenceSnapshot(['week' => 8, 'home_score' => 10, 'away_score' => 20]));
    $report = app(NflEvidenceLeaderboard::class)->report(['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1', 'signal_key' => 'qb_form_home_edge']);
    $winner = collect($report['rows'])->firstWhere('market', 'winner');
    expect($winner['discovery']['wins'])->toBe(1)->and($winner['later']['losses'])->toBe(1)
        ->and($winner['weeks'])->toHaveCount(2)->and($winner['validated'])->toBeFalse();
});

it('classifies divisional, conference-only, interconference and unknown alignment', function () {
    $snapshot = evidenceSnapshot();
    $game = Game::with(['homeTeam', 'awayTeam'])->find($snapshot->game_id);
    $service = app(NflEvidenceLeaderboard::class);
    expect($service->cohort($game))->toBe('divisional');
    $game->awayTeam->abbreviation = 'SEA';
    expect($service->cohort($game))->toBe('conference_non_divisional');
    $game->awayTeam->abbreviation = 'KC';
    expect($service->cohort($game))->toBe('interconference');
    $game->season = 2001;
    expect($service->cohort($game))->toBe('unknown');
    $game->season = 2024;
    $game->awayTeam->abbreviation = '???';
    expect($service->cohort($game))->toBe('unknown');
});

it('exposes the leaderboard through the existing report command and rejects invalid scopes', function () {
    evidenceGrade(evidenceSnapshot());
    $this->artisan('nfl:report-signal-grades', ['--leaderboard' => true, '--season' => 2024, '--model-version' => 'nfl-model-v1', '--cohort' => 'divisional'])
        ->expectsOutputToContain('"inventory"')->assertSuccessful();
    $this->artisan('nfl:report-signal-grades', ['--leaderboard' => true, '--cohort' => 'invalid'])->assertFailed();
});

it('exposes the admin leaderboard even without a registered model artifact', function () {
    $this->withoutVite();
    app(NflMonitorReportCache::class)->refresh();
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/nfl-model-monitoring')->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/NflModelMonitoring')
            ->where('artifact', null)->has('evidence_leaderboard.inventory', 5)
            ->where('evidence_leaderboard.selected_observations', 0)
            ->where('evidence_leaderboard.prediction_effect', false));
});

it('rejects unsafe and observation-level leaderboard modes', function () {
    $this->artisan('nfl:report-signal-grades', ['--leaderboard' => true, '--include-unsafe' => true])->assertFailed();
    $this->artisan('nfl:report-signal-grades', ['--leaderboard' => true, '--sample-unit' => 'observation'])->assertFailed();
    $this->artisan('nfl:report-signal-grades', ['--leaderboard' => true, '--season' => 'nonsense'])->assertFailed();
});

it('accepts admin scope filters and validates them', function () {
    $this->withoutVite();
    evidenceGrade(evidenceSnapshot());
    $cache = app(NflMonitorReportCache::class);
    $cache->read(null, ['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1', 'validation_from_week' => 7]);
    $cache->refresh();
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/nfl-model-monitoring?evidence_season=2024&evidence_model=nfl-model-v1&evidence_week=7')
        ->assertSuccessful()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evidence_leaderboard.scope.from_season', 2024)
        ->where('evidence_leaderboard.scope.validation_from_week', 7)
        ->where('evidence_leaderboard.scope.model_version', 'nfl-model-v1')
        ->where('evidence_leaderboard.selected_observations', 6));
    $this->getJson('/admin/nfl-model-monitoring?evidence_season=invalid')->assertUnprocessable();
});

it('keeps pushes out of win rate and does not fabricate missing baselines', function () {
    $snapshot = evidenceSnapshot(['home_score' => 20, 'away_score' => 20]);
    $outputs = $snapshot->outputs;
    unset($outputs['baseline_win_probability']);
    $snapshot->outputs = $outputs;
    $snapshot->save();
    evidenceGrade($snapshot);
    $report = app(NflEvidenceLeaderboard::class)->report(['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1', 'signal_key' => 'qb_form_home_edge']);
    $winner = collect($report['rows'])->firstWhere('market', 'winner');
    expect($winner['sample'])->toBe(0)->and($winner['pushes'])->toBe(1)
        ->and($winner['hit_rate'])->toBeNull()->and($winner['wilson_lower'])->toBeNull()
        ->and($winner['baseline_lift'])->toBeNull()->and($winner['paired_baseline_sample'])->toBe(0);
});

it('does not combine different configurations or misreport identical game samples as independent signals', function () {
    $snapshot = evidenceSnapshot();
    evidenceGrade($snapshot);
    $copy = $snapshot->replicate();
    $copy->snapshot_run_id = (string) Str::uuid();
    $run = $snapshot->modelRun->replicate();
    $run->id = (string) Str::uuid();
    $run->config_hash = str_repeat('f', 64);
    $run->save();
    $copy->model_run_id = $run->id;
    $copy->unsetRelation('modelRun');
    $copy->save();
    evidenceGrade($copy);
    $report = app(NflEvidenceLeaderboard::class)->report(['from_season' => 2024, 'to_season' => 2024, 'model_version' => 'nfl-model-v1']);
    $rows = collect($report['rows'])->where('market', 'winner')->where('signal_key', 'qb_form_home_edge');
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['sample'])->toBe(1)->and($row['identical_sample_signals'])->not->toBeEmpty();
    }
});
