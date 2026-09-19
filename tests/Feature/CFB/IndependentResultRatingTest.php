<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Ratings\ResultRatingEvidence;
use App\Services\CFB\Ratings\ResultRatingModel;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('freezes independent result evidence and excludes current-game or later-arriving results', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19T12:00:00-05:00'));
    $h = Team::factory()->create();
    $a = Team::factory()->create();
    $history = Game::factory()->create(['season' => 2025, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
        'status' => 'STATUS_FINAL', 'home_score' => 35, 'away_score' => 7, 'game_date' => '2025-09-01']);
    $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $h->id, 'away_team_id' => $a->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-20']);
    $service = app(ResultRatingEvidence::class);
    $cap = now()->toImmutable();
    $cutoff = $cap->addDay();
    $evidence = $service->forGame($game, $cap, $cutoff);
    expect($evidence['home']['current_games'])->toBe(0)->and($evidence['home']['prior_games'])->toBe(1)
        ->and($evidence['minimum_team_games'])->toBe(1)->and($evidence['home_margin'])->toBeGreaterThan(0)
        ->and(DB::table('cfb_result_rating_runs')->count())->toBe(1);
    expect($service->forGame($game, $cap, $cutoff))->toBe($evidence);
    $this->travel(1)->hours();
    $history->update(['home_score' => 70]);
    expect($service->forGame($game, $cap, $cutoff))->toBeNull();
    expect(DB::table('cfb_result_rating_runs')->count())->toBe(1);
    expect($service->forGame($game, $cutoff, $cutoff))->toBeNull();
});

test('canonical fallback uses independent points and never transfers FPI signal weights', function () {
    $configuration = app(CfbCalculationReleaseDefinition::class)->configuration();
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.6.3', 'test', 'test', 'cfb-pregame-v1', $configuration);
    $team = ['elo' => 1500, 'metrics' => null, 'injuries' => []];
    $inputs = ['event' => ['season' => 2026], 'require_versioned_elo' => true, 'home' => $team, 'away' => $team,
        'independent_result_rating' => ['version' => ResultRatingModel::VERSION, 'run_id' => 1, 'input_hash' => str_repeat('a', 64), 'status' => 'observed_result_rating', 'minimum_team_games' => 2, 'home_margin' => 24.5, 'total' => 51.5,
            'home' => ['current_games' => 2, 'prior_games' => 12], 'away' => ['current_games' => 0, 'prior_games' => 2]]];
    $result = app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, now()->toImmutable()), $release);
    expect($result->metadata['home_margin'])->toBe(24.5)->and($result->metadata['input_quality']['qualified'])->toBeTrue()
        ->and($result->metadata['input_quality']['sample_games']['away'])->toBe(0)
        ->and($result->metadata['football_signal_summary']['applied'])->toBe(0)
        ->and($result->diagnostics['spread_baseline'])->toBe('independent_completed_results');
    expect(collect($result->markets)->firstWhere('marketType', 'spread')->projectedLine)->toBe(-24.5);
    $without = $configuration;
    unset($without['independent_result_rating']);
    expect(CfbFootballSignalEvidence::baselineHash($configuration))->toBe(CfbFootballSignalEvidence::baselineHash($without));
});

test('invalid independent evidence never clears missing inputs or enters canonical fallback', function () {
    $configuration = app(CfbCalculationReleaseDefinition::class)->configuration();
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.6.3', 'test', 'test', 'cfb-pregame-v1', $configuration);
    $team = ['elo' => 1500, 'metrics' => null, 'injuries' => []];
    $evidence = ['version' => ResultRatingModel::VERSION, 'run_id' => 1, 'input_hash' => str_repeat('a', 64),
        'status' => 'observed_result_rating', 'minimum_team_games' => 2, 'home_margin' => 24.5, 'total' => 51.5,
        'home' => ['current_games' => 2, 'prior_games' => 12], 'away' => ['current_games' => 0, 'prior_games' => 2]];
    foreach ([['total' => -10], ['home_margin' => INF], ['home_margin' => NAN], ['minimum_team_games' => 10], ['run_id' => null]] as $invalid) {
        $inputs = ['event' => ['season' => 2026], 'require_versioned_elo' => true, 'home' => $team, 'away' => $team,
            'independent_result_rating' => [...$evidence, ...$invalid]];
        $result = app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, now()->toImmutable()), $release);
        expect($result->metadata['input_quality']['qualified'])->toBeFalse()
            ->and($result->diagnostics['spread_baseline'])->not->toBe('independent_completed_results');
    }
});
