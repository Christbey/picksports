<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\NFL\NflInjuryTotalAdjustment;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\NFL\NflSpreadProbability;
use App\Services\NFL\NflTeamHistory;
use App\Services\Sports\DepthChartImpactService;

it('separates offensive and defensive total effects and excludes unknown positions', function () {
    $service = app(NflInjuryTotalAdjustment::class);
    $off = ['units' => ['offense' => ['out' => 2.0]]];
    $def = ['units' => ['defense' => ['out' => 2.0]]];
    expect($service->calculate($off, [])['adjustment'])->toBe(-0.6)
        ->and($service->calculate($def, [])['adjustment'])->toBe(0.6)
        ->and($service->calculate($off, $def)['adjustment'])->toBe(0.0)
        ->and($service->calculate(['out' => 25, 'units' => ['unknown' => ['out' => 25]]], [])['adjustment'])->toBe(0.0)
        ->and($service->calculate(['units' => ['offense' => ['out' => 100]]], [])['adjustment'])->toBe(-3.0)
        ->and($service->unit('CB'))->toBe('defense')->and($service->unit('OT'))->toBe('offense')
        ->and($service->unit('T'))->toBe('offense')
        ->and($service->unit('K'))->toBe('unknown');
});

it('preserves unit attribution through the injury snapshot and archive fallback', function () {
    $team = Team::factory()->create();
    $entries = collect(['WR', 'CB'])->map(function ($position) use ($team) {
        $p = Player::factory()->create(['team_id' => $team->id, 'position' => $position]);

        return (new PlayerInjury(['player_id' => $p->id, 'status' => 'Out', 'injury_date' => '2026-09-20', 'return_date' => '2026-10-10']))->setRelation('player', $p);
    });
    $impact = Mockery::mock(DepthChartImpactService::class);
    $impact->shouldReceive('injuryMultiplier')->twice()->andReturn(1.5);
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class, [$impact])->makePartial()->shouldAllowMockingProtectedMethods();
    $action->shouldReceive('pointInTimeInjuryStateForTeam')->andReturn(['as_of' => now(), 'entries' => $entries,
        'source' => 'append_only_snapshot', 'snapshot_uuid' => 'test', 'snapshot_observed_at' => now()->toIso8601String()]);
    $action->shouldReceive('nflverseInjuryCountsForTeam')->andReturn(['out' => 1, 'questionable' => 0, 'rows' => 1,
        'units' => ['defense' => ['out' => 1]]]);
    $g = new Game(['season' => 2026, 'game_date' => '2026-09-24']);
    $counts = (new ReflectionMethod($action, 'injuryCountsForTeam'))->invoke($action, $team->id, 2026, $g);
    expect($counts['out'])->toBe(4.0)->and($counts['units']['offense']['out'])->toBe(1.5)
        ->and($counts['units']['defense']['out'])->toBe(2.5);
});

it('retains historical evidence without letting it change forecast points', function () {
    $a = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    foreach (['homeAwayStrengthContext', 'weatherTotalContext', 'scheduleSpotContext', 'coachingContext', 'priorSeasonPedigreeContext'] as $method) {
        $a->shouldReceive($method)->andReturn(['applied' => false, 'spread_adjustment' => 0.0, 'total_adjustment' => 0.0]);
    }
    foreach (['divisionRivalryContext', 'matchupRecordContext', 'sameWeekRecordContext'] as $method) {
        $a->shouldReceive($method)->andReturn(['applied' => true, 'games' => 3, 'spread_adjustment' => 4.0, 'total_adjustment' => -2.0]);
    }
    $result = (new ReflectionMethod($a, 'applyContextualFactorsBlend'))->invoke($a, new Game, 2.5, .6, 44.0);
    $m = (new ReflectionProperty($a, 'lastModelMetadata'))->getValue($a)['contextual_factors'];
    expect($result[0])->toBe(2.5)->and($result[2])->toBe(44.0)
        ->and($m['same_week_records']['games'])->toBe(3)
        ->and($m['same_week_records']['evidence_available'])->toBeTrue()
        ->and($m['same_week_records']['spread_adjustment'])->toBe(0.0)
        ->and($m['same_week_records']['affects_prediction'])->toBeFalse();
});

it('labels small positive and negative ATS differences as passes and blocks release', function ($edge) {
    $p = app(NflSpreadProbability::class)->estimate([], 4.5 + $edge, -4.5);
    expect($p['recommendation'])->toBe('pass_small_edge')->and($p['cover_probability'])->toBeNull();
    $prediction = new Prediction(['model_metadata' => ['analysis_layer' => [
        'applied' => true, 'bet_classification' => 'bet', 'eligibility' => ['eligible' => true],
        'calculated_edge' => ['spread_points' => $edge], 'pro_signal_layer' => ['tier' => 'official_candidate',
            'recommended_markets' => [['market' => 'spread', 'tier' => 'official_candidate', 'score' => 99]]],
    ]]]);
    expect(app(NflReleasedBetDecisionRecorder::class)->candidateMarketKeys($prediction))->toBe([]);
})->with([0.2, -0.2, 0.0, 1.9, -1.9]);

it('uses the returning QBs prior offense without replacing the current defense', function () {
    $team = Team::factory()->create();
    $opp = Team::factory()->create();
    foreach ([['2025-11-01', 2025, 'Returning QB', 30, 20, 400], ['2025-11-08', 2025, 'Returning QB', 30, 20, 400],
        ['2026-09-06', 2026, 'Backup QB', 3, 27, 180], ['2026-09-13', 2026, 'Backup QB', 3, 27, 180],
        ['2026-09-24', 2026, 'Returning QB', 99, 0, 900]] as [$date, $season, $qb, $pf, $pa, $yards]) {
        $g = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opp->id,
            'season' => $season, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'game_date' => $date,
            'home_qb_name' => $qb, 'home_score' => $pf, 'away_score' => $pa]);
        foreach ([$team, $opp] as $index => $t) {
            TeamStat::create(['game_id' => $g->id, 'team_id' => $t->id, 'team_type' => $index ? 'away' : 'home',
                'total_yards' => $index ? 300 : $yards, 'passing_attempts' => 30, 'rushing_attempts' => 20,
                'rushing_yards' => 80, 'sacks_allowed' => $qb === 'Backup QB' && ! $index ? 6 : 1]);
        }
    }
    $target = new Game(['home_team_id' => $team->id, 'away_team_id' => $opp->id, 'season' => 2026, 'game_date' => '2026-09-24']);
    $history = new NflTeamHistory($target, fn () => 1500, [$team->id => ['qb_name' => 'Returning QB']]);
    $totals = $history->totals($team->id);
    $line = $history->line($team->id);
    expect($totals['points_for'])->toBe(30.0)->and($totals['points_against'])->toBe(27.0)
        ->and($totals['qb_context']['offense_games'])->toBe(2)
        ->and($totals['qb_context']['excluded_other_or_unknown_qb_games'])->toBe(2)
        ->and($totals['qb_context']['prior_season_fallback'])->toBeTrue()
        ->and($line['off_sack_allowed_rate'])->toBe(round(2 / 60, 4));
    $unknown = new NflTeamHistory($target, fn () => 1500, [$team->id => ['qb_name' => 'New QB']]);
    expect($unknown->totals($team->id)['games'])->toBe(0)->and($unknown->line($team->id)['games'])->toBe(0);
});
