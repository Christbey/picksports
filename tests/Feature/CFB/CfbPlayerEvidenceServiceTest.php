<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbInputSnapshotBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'America/Chicago'));
});

function cfbTimestampedPasser(): array
{
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => 'observed-qb', 'full_name' => 'Observed QB', 'position' => 'QB']);
    $past = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-10-17', 'game_time' => '11:00:00',
        'status' => 'STATUS_FINAL']);
    $stat = PlayerStat::factory()->create(['player_id' => $player->id, 'team_id' => $team->id, 'game_id' => $past->id,
        'passing_attempts' => 30, 'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00']);
    $target = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-10-24', 'status' => 'STATUS_SCHEDULED']);

    return compact('team', 'player', 'past', 'stat', 'target');
}

it('keeps a former team passer in historical evidence using the stat team rather than mutable roster membership', function () {
    $f = cfbTimestampedPasser();
    $f['player']->update(['team_id' => Team::factory()->create()->id]);
    $evidence = app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, now()->toImmutable());
    expect($evidence['last_observed_player_id'])->toBe($f['player']->id)
        ->and($evidence['status'])->toBe('last_observed')->and($evidence['confirmed_starter'])->toBeFalse();
});

it('excludes reports created, changed, or source-observed after snapshot capture', function (string $lateField) {
    $f = cfbTimestampedPasser();
    $injury = PlayerInjury::create(['team_id' => $f['team']->id, 'player_id' => $f['player']->id,
        'injury_key' => 'capture-boundary', 'status' => 'Out', 'is_active' => true, 'injury_date' => '2026-10-20',
        'source_updated_at' => '2026-10-21 10:00:00']);
    $injury->forceFill(['created_at' => '2026-10-21 10:00:00', 'updated_at' => '2026-10-21 10:00:00',
        $lateField => '2026-10-21 11:30:00'])->save();
    $capture = CarbonImmutable::parse('2026-10-21 11:00:00', 'America/Chicago');
    $evidence = app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, $capture);
    expect($evidence['quarterback_hold'])->toBeFalse()
        ->and($evidence['injury_coverage'])->toBe('unknown_or_no_reported_injuries');
})->with(['created_at', 'updated_at', 'source_updated_at']);

it('excludes stat backfills or corrections unavailable at capture', function (string $field) {
    $f = cfbTimestampedPasser();
    $f['stat']->forceFill([$field => '2026-10-21 11:30:00'])->save();
    $capture = CarbonImmutable::parse('2026-10-21 11:00:00', 'America/Chicago');
    expect(app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, $capture)['status'])->toBe('unknown');
})->with(['created_at', 'updated_at']);

it('includes observations at the capture boundary and records provenance without claiming a confirmed starter', function () {
    $f = cfbTimestampedPasser();
    $capture = CarbonImmutable::parse('2026-10-21 11:00:00', 'America/Chicago');
    $injury = PlayerInjury::create(['team_id' => $f['team']->id, 'player_id' => $f['player']->id,
        'injury_key' => 'on-boundary', 'status' => 'Out', 'is_active' => true,
        'injury_date' => '2026-10-20', 'source_updated_at' => $capture]);
    $injury->forceFill(['created_at' => $capture, 'updated_at' => $capture])->save();
    $evidence = app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, $capture);
    expect($evidence['quarterback_hold'])->toBeTrue()->and($evidence['confirmed_starter'])->toBeFalse()
        ->and($evidence['as_of'])->toBe($capture->toIso8601String())
        ->and($evidence['injury_record_count'])->toBe(1)
        ->and(CarbonImmutable::parse($evidence['injury_records_updated_at'])->equalTo($capture))->toBeTrue()
        ->and(CarbonImmutable::parse($evidence['history_record_updated_at'])->lte($capture))->toBeTrue();
});

it('retains overtime game history but excludes source games dated after capture', function () {
    $f = cfbTimestampedPasser();
    $f['past']->update(['status' => 'STATUS_FINAL_OVERTIME']);
    $capture = CarbonImmutable::parse('2026-10-21 11:00:00', 'America/Chicago');
    expect(app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, $capture)['status'])->toBe('last_observed');
    $f['past']->update(['game_date' => '2026-10-22']);
    expect(app(CfbPlayerEvidenceService::class)->quarterback($f['target'], $f['team']->id, $capture)['status'])->toBe('unknown');
});

it('passes snapshot capture through the builder even if a report arrives while the snapshot is being built', function () {
    $f = cfbTimestampedPasser();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026,
        'starts_at' => now()->addDays(3)]);
    $f['target']->update(['sport_event_id' => $event->id]);
    $capture = now()->toImmutable();
    $real = new CfbPlayerEvidenceService;
    $arrived = false;
    $this->mock(CfbPlayerEvidenceService::class)->shouldReceive('quarterback')->twice()
        ->withArgs(fn ($game, $id, $asOf) => $asOf->equalTo($capture))
        ->andReturnUsing(function ($game, $id, $asOf) use ($f, $real, &$arrived) {
            if (! $arrived) {
                $arrived = true;
                $this->travelTo(now()->addMinute());
                PlayerInjury::create(['team_id' => $f['team']->id, 'player_id' => $f['player']->id,
                    'injury_key' => 'arrived-during-build', 'status' => 'Out', 'is_active' => true,
                    'injury_date' => '2026-10-20', 'source_updated_at' => now()]);
            }

            return $real->quarterback($game, $id, $asOf);
        });
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $config['inputs']['point_in_time_elo'] = false;
    $config['inputs']['personnel_evidence'] = false;
    $config['inputs']['validated_historical_play_metrics'] = false;
    $config['football_signals']['enabled'] = false;
    $config['candidate_suite']['enabled'] = false;
    $config['independent_result_rating']['enabled'] = false;
    $config['scoring_challenger'] = [];
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules',
        'test', 'test', 'test', 'cfb-pregame-v1', $config);
    $snapshot = app(CfbInputSnapshotBuilder::class)->build($event, $release);
    expect($snapshot->inputs['home']['availability']['quarterback_hold'])->toBeFalse()
        ->and($snapshot->inputs['home']['availability']['injury_record_count'])->toBe(0)
        ->and($snapshot->sourceTimestamps['home_quarterback_history'])->toBe($f['stat']->updated_at->toIso8601String())
        ->and($snapshot->latestSourceAvailableAt->lte($snapshot->capturedAt))->toBeTrue();
});
