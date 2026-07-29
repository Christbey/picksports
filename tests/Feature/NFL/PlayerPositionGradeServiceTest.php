<?php

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\DepthChartSnapshotEntry;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\PlayerInjurySnapshotEntry;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\NFL\PlayerPositionGradeService;

it('grades the offensive line as a point-in-time unit with sample confidence', function () {
    $team = Team::query()->create([
        'espn_id' => 'ol-grade-team',
        'abbreviation' => 'OLG',
        'location' => 'Line',
        'name' => 'Graders',
    ]);
    $opponent = Team::query()->create([
        'espn_id' => 'ol-grade-opponent',
        'abbreviation' => 'OLO',
        'location' => 'Rush',
        'name' => 'Defense',
    ]);
    $lineman = Player::query()->create([
        'espn_id' => 'ol-grade-player',
        'team_id' => $team->id,
        'first_name' => 'Left',
        'last_name' => 'Tackle',
        'full_name' => 'Left Tackle',
        'position' => 'LT',
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $team->id,
        'player_id' => $lineman->id,
        'season' => 2026,
        'position_slot_key' => 'LT',
        'position_code' => 'LT',
        'depth_rank' => 1,
        'is_starter' => true,
        'source_updated_at' => '2026-09-01 12:00:00',
    ]);

    foreach ([1, 2, 3, 4] as $week) {
        $game = Game::query()->create([
            'espn_event_id' => 'ol-grade-game-'.$week,
            'season' => 2026,
            'week' => $week,
            'season_type' => 'regular',
            'game_date' => sprintf('2026-09-%02d', 1 + ($week * 7)),
            'game_time' => '12:00:00',
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'home_score' => 24,
            'away_score' => 17,
            'neutral_site' => false,
        ]);

        TeamStat::query()->create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'team_type' => 'home',
            'passing_attempts' => 32,
            'sacks_allowed' => 1,
            'rushing_attempts' => 28,
            'rushing_yards' => 132,
        ]);
    }

    $report = app(PlayerPositionGradeService::class)->teamReport(
        $team->id,
        2026,
        '2026-10-15 12:00:00',
    );
    $offensiveLine = collect($report['groups'])->firstWhere('group', 'OL');

    expect($offensiveLine)
        ->not->toBeNull()
        ->and($offensiveLine['grade'])->toBeGreaterThan(70)
        ->and($offensiveLine['coverage_rate'])->toBe(1.0)
        ->and($offensiveLine['grade_confidence'])->toBe(0.5)
        ->and($offensiveLine['sample_games'])->toBe(4)
        ->and($offensiveLine['grade_source'])->toBe('team_pass_protection_and_run_blocking')
        ->and(data_get($report, 'summary.overall_grade'))->toBe($offensiveLine['grade'])
        ->and(data_get($report, 'summary.grade_confidence'))->toBe(0.5);
});

it('uses the latest append-only depth chart known before the requested timestamp', function () {
    $team = Team::query()->create([
        'espn_id' => 'snapshot-grade-team',
        'abbreviation' => 'SGT',
        'location' => 'Snapshot',
        'name' => 'Team',
    ]);
    $oldStarter = Player::query()->create([
        'espn_id' => 'snapshot-old-starter',
        'team_id' => $team->id,
        'first_name' => 'Old',
        'last_name' => 'Starter',
        'full_name' => 'Old Starter',
        'position' => 'QB',
    ]);
    $newStarter = Player::query()->create([
        'espn_id' => 'snapshot-new-starter',
        'team_id' => $team->id,
        'first_name' => 'New',
        'last_name' => 'Starter',
        'full_name' => 'New Starter',
        'position' => 'QB',
    ]);

    $oldSnapshot = DepthChartSnapshot::query()->create([
        'snapshot_uuid' => '10000000-0000-4000-8000-000000000001',
        'team_id' => $team->id,
        'espn_team_id' => $team->espn_id,
        'season' => 2026,
        'provider' => 'espn',
        'observed_at' => '2026-09-01 10:00:00',
        'source_updated_at' => '2026-09-01 09:55:00',
        'payload_hash' => hash('sha256', 'old-depth-chart'),
        'entry_count' => 1,
    ]);
    DepthChartSnapshotEntry::query()->create([
        'snapshot_id' => $oldSnapshot->id,
        'player_id' => $oldStarter->id,
        'position_slot_key' => 'QB',
        'position_code' => 'QB',
        'espn_athlete_id' => $oldStarter->espn_id,
        'depth_rank' => 1,
        'is_starter' => true,
        'observed_at' => '2026-09-01 10:00:00',
        'source_updated_at' => '2026-09-01 09:55:00',
    ]);

    $newSnapshot = DepthChartSnapshot::query()->create([
        'snapshot_uuid' => '10000000-0000-4000-8000-000000000002',
        'team_id' => $team->id,
        'espn_team_id' => $team->espn_id,
        'season' => 2026,
        'provider' => 'espn',
        'observed_at' => '2026-10-01 10:00:00',
        'source_updated_at' => '2026-10-01 09:55:00',
        'payload_hash' => hash('sha256', 'new-depth-chart'),
        'entry_count' => 1,
    ]);
    DepthChartSnapshotEntry::query()->create([
        'snapshot_id' => $newSnapshot->id,
        'player_id' => $newStarter->id,
        'position_slot_key' => 'QB',
        'position_code' => 'QB',
        'espn_athlete_id' => $newStarter->espn_id,
        'depth_rank' => 1,
        'is_starter' => true,
        'observed_at' => '2026-10-01 10:00:00',
        'source_updated_at' => '2026-10-01 09:55:00',
    ]);

    $report = app(PlayerPositionGradeService::class)->teamReport(
        $team->id,
        2026,
        '2026-09-15 12:00:00',
    );

    expect(data_get($report, 'summary.depth_chart_source'))->toBe('append_only_snapshot')
        ->and(data_get($report, 'summary.depth_chart_snapshot_uuid'))->toBe($oldSnapshot->snapshot_uuid)
        ->and(data_get($report, 'summary.depth_entries'))->toBe(1)
        ->and(data_get($report, 'players.0.player'))->toBe('Old Starter');
});

it('computes if-in if-out and expected roster grades from the latest pregame injury snapshot', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $starter = Player::factory()->create([
        'team_id' => $team->id,
        'full_name' => 'Starting Quarterback',
        'position' => 'QB',
    ]);
    $replacement = Player::factory()->create([
        'team_id' => $team->id,
        'full_name' => 'Backup Quarterback',
        'position' => 'QB',
    ]);
    $priorGame = Game::factory()->create([
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-01',
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
    ]);

    PlayerStat::query()->create([
        'player_id' => $starter->id,
        'game_id' => $priorGame->id,
        'team_id' => $team->id,
        'passing_attempts' => 35,
        'passing_yards' => 315,
        'passing_touchdowns' => 3,
        'interceptions_thrown' => 0,
        'sacks_taken' => 1,
        'rushing_yards' => 24,
    ]);
    PlayerStat::query()->create([
        'player_id' => $replacement->id,
        'game_id' => $priorGame->id,
        'team_id' => $team->id,
        'passing_attempts' => 35,
        'passing_yards' => 175,
        'passing_touchdowns' => 1,
        'interceptions_thrown' => 2,
        'sacks_taken' => 4,
        'rushing_yards' => 0,
    ]);

    $depthSnapshot = DepthChartSnapshot::query()->create([
        'snapshot_uuid' => '20000000-0000-4000-8000-000000000001',
        'team_id' => $team->id,
        'espn_team_id' => $team->espn_id,
        'season' => 2026,
        'provider' => 'espn',
        'observed_at' => '2026-09-10 10:00:00',
        'source_updated_at' => '2026-09-10 09:55:00',
        'payload_hash' => hash('sha256', 'availability-depth-chart'),
        'entry_count' => 2,
    ]);
    foreach ([[$starter, 1, true], [$replacement, 2, false]] as [$player, $rank, $isStarter]) {
        DepthChartSnapshotEntry::query()->create([
            'snapshot_id' => $depthSnapshot->id,
            'player_id' => $player->id,
            'position_slot_key' => 'QB',
            'position_code' => 'QB',
            'espn_athlete_id' => $player->espn_id,
            'depth_rank' => $rank,
            'is_starter' => $isStarter,
            'observed_at' => '2026-09-10 10:00:00',
            'source_updated_at' => '2026-09-10 09:55:00',
        ]);
    }

    $pregameSnapshot = PlayerInjurySnapshot::query()->create([
        'snapshot_uuid' => '30000000-0000-4000-8000-000000000001',
        'team_id' => $team->id,
        'espn_team_id' => $team->espn_id,
        'provider' => 'espn',
        'observed_at' => '2026-09-14 10:00:00',
        'source_updated_at' => '2026-09-14 09:55:00',
        'payload_hash' => hash('sha256', 'pregame-questionable'),
        'entry_count' => 1,
    ]);
    PlayerInjurySnapshotEntry::query()->create([
        'snapshot_id' => $pregameSnapshot->id,
        'player_id' => $starter->id,
        'espn_athlete_id' => $starter->espn_id,
        'injury_key' => 'starter-questionable',
        'status' => 'Questionable',
        'observed_at' => '2026-09-14 10:00:00',
        'source_updated_at' => '2026-09-14 09:55:00',
    ]);

    $postgameSnapshot = PlayerInjurySnapshot::query()->create([
        'snapshot_uuid' => '30000000-0000-4000-8000-000000000002',
        'team_id' => $team->id,
        'espn_team_id' => $team->espn_id,
        'provider' => 'espn',
        'observed_at' => '2026-09-16 10:00:00',
        'source_updated_at' => '2026-09-16 09:55:00',
        'payload_hash' => hash('sha256', 'postgame-out'),
        'entry_count' => 1,
    ]);
    PlayerInjurySnapshotEntry::query()->create([
        'snapshot_id' => $postgameSnapshot->id,
        'player_id' => $starter->id,
        'espn_athlete_id' => $starter->espn_id,
        'injury_key' => 'starter-out',
        'status' => 'Out',
        'observed_at' => '2026-09-16 10:00:00',
        'source_updated_at' => '2026-09-16 09:55:00',
    ]);

    $report = app(PlayerPositionGradeService::class)->teamReport(
        $team->id,
        2026,
        '2026-09-15 12:00:00',
    );

    expect(data_get($report, 'availability.snapshot_uuid'))->toBe($pregameSnapshot->snapshot_uuid)
        ->and(data_get($report, 'availability.players.0.availability_probability'))->toBe(0.6)
        ->and(data_get($report, 'availability.players.0.replacement_player'))->toBe('Backup Quarterback')
        ->and(data_get($report, 'summary.if_in_grade'))->toBeGreaterThan(data_get($report, 'summary.expected_grade'))
        ->and(data_get($report, 'summary.expected_grade'))->toBeGreaterThan(data_get($report, 'summary.if_out_grade'))
        ->and(data_get($report, 'availability.players.0.usage_source'))->toBe('depth_chart_role_proxy');
});
