<?php

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\DepthChartSnapshotEntry;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\PlayerInjurySnapshotEntry;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflMatchupPersonnel;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function personnelFixture(array $changes = []): array
{
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $charts = [];
    $games = [];
    foreach ([1 => '06', 2 => '13', 3 => '20', 4 => '27'] as $week => $day) {
        $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $other->id,
            'season' => 2026, 'season_type' => '2', 'week' => $week, 'game_date' => "2026-09-{$day}", 'game_time' => '17:00:00',
            'status' => $week === 4 ? 'STATUS_SCHEDULED' : 'STATUS_FINAL']);
        $at = CarbonImmutable::parse("2026-09-{$day} 12:00:00")->subDay();
        $chart = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id,
            'espn_team_id' => $team->espn_id, 'season' => 2026, 'observed_at' => $at, 'payload_hash' => hash('sha256', (string) $week), 'entry_count' => 6]);
        foreach (['LT', 'LG', 'C', 'RG', 'RT', 'RB'] as $index => $position) {
            DepthChartSnapshotEntry::create(['snapshot_id' => $chart->id, 'position_slot_key' => strtolower($position),
                'position_code' => $position, 'espn_athlete_id' => $week === 4 ? ($changes[$position] ?? (string) (100 + $index)) : (string) (100 + $index),
                'depth_rank' => 1, 'observed_at' => $at]);
        }
        $charts[] = $chart;
        $games[] = $game;
    }
    $target = $games[3];
    $prediction = Prediction::factory()->create(['game_id' => $target->id]);
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $target, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => [
            'game_id' => $target->id, 'team_id' => $team->id, 'side' => 'home', 'snapshot_id' => $charts[3]->id,
            'snapshot_uuid' => $charts[3]->snapshot_uuid, 'as_of' => now()->toIso8601String(),
        ]]]],
    ]);
    $injury = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $team->id,
        'espn_team_id' => $team->espn_id, 'observed_at' => now(), 'payload_hash' => hash('sha256', 'injury')]);
    $entry = PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => '105',
        'injury_key' => 'rb', 'status' => 'Active', 'observed_at' => now()]);

    return [$target, $charts, $games, $injury, $entry];
}

beforeEach(function () {
    $this->travelTo('2026-09-26 18:00:00');
});

it('counts projected changes by position and requires four adjacent pregame charts for continuity', function () {
    [$target, $charts, $games] = personnelFixture(['LT' => '200', 'RG' => '201']);
    $service = app(NflMatchupPersonnel::class);
    $cutoff = CarbonImmutable::parse('2026-09-27T17:00:00Z');
    $rows = $service->forGame($target, $cutoff)[$target->home_team_id];
    expect($rows['ol_changed']['value'])->toBe(2)
        ->and($rows['ol_changed_two']['value'])->toBe(2)
        ->and($rows['ol_same_four']['value'])->toBe(0)
        ->and($rows['ol_same_four']['eligible'])->toBeTrue()
        ->and($rows['ol_changed']['personnel']['previous_lineups'][0]['game_id'])->toBe($games[2]->id);
    $signals = app(NflMatchupSignalService::class)->build($target);
    $signal = collect($signals['signals'])->first(fn ($s) => $s['id'] === 168 && $s['offense_team_id'] === $target->home_team_id);
    expect($signal['status'])->toBe('matched')->and($signal['evidence']['personnel_only'])->toBeTrue();
    $games[2]->update(['week' => 2]);
    expect($service->forGame($target, $cutoff)[$target->home_team_id]['ol_changed']['eligible'])->toBeFalse();
});

it('recognizes unchanged projected lineups without treating missing or duplicate positions as continuity', function () {
    [$target, $charts] = personnelFixture();
    $service = app(NflMatchupPersonnel::class);
    $cutoff = CarbonImmutable::parse('2026-09-27T17:00:00Z');
    expect($service->forGame($target, $cutoff)[$target->home_team_id]['ol_same_four']['value'])->toBe(4);
    $charts[2]->entries()->where('position_code', 'LT')->update(['espn_athlete_id' => '101']);
    expect($service->forGame($target, $cutoff)[$target->home_team_id]['ol_changed']['eligible'])->toBeFalse();
});

it('does not use charts published after kickoff to reconstruct a prior lineup', function () {
    [$target, $charts] = personnelFixture();
    $charts[2]->update(['observed_at' => '2026-09-21 18:00:00']);
    $rows = app(NflMatchupPersonnel::class)->forGame($target, CarbonImmutable::parse('2026-09-27T17:00:00Z'));
    expect($rows[$target->home_team_id]['ol_changed']['eligible'])->toBeFalse();
});

it('distinguishes confirmed absence available and uncertain RB injury evidence', function (string $status, ?int $value) {
    [$target, , , , $entry] = personnelFixture();
    $entry->update(['status' => $status]);
    $row = app(NflMatchupPersonnel::class)->forGame($target, CarbonImmutable::parse('2026-09-27T17:00:00Z'))[$target->home_team_id]['rb1_out'];
    expect($row['eligible'])->toBe($value !== null)->and($row['value'])->toBe($value);
})->with([['Out', 1], ['Active', 0], ['Injured Reserve', 1], ['Questionable', null], ['Doubtful', null]]);

it('requires backup depth evidence and the prior centers absence before calling a replacement a backup', function () {
    [$target, $charts, , $injury] = personnelFixture(['C' => '202']);
    $service = app(NflMatchupPersonnel::class);
    $cutoff = CarbonImmutable::parse('2026-09-27T17:00:00Z');
    expect($service->forGame($target, $cutoff)[$target->home_team_id]['backup_center']['eligible'])->toBeFalse();
    DepthChartSnapshotEntry::create(['snapshot_id' => $charts[2]->id, 'position_slot_key' => 'c', 'position_code' => 'C',
        'espn_athlete_id' => '202', 'depth_rank' => 2, 'observed_at' => $charts[2]->observed_at]);
    PlayerInjurySnapshotEntry::create(['snapshot_id' => $injury->id, 'espn_athlete_id' => '102', 'injury_key' => 'center', 'status' => 'Out', 'observed_at' => now()]);
    expect($service->forGame($target, $cutoff)[$target->home_team_id]['backup_center']['value'])->toBe(1);
});
