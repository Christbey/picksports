<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\NFL\Research\RecommendationEligibility;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('nfl');
beforeEach(fn () => $this->travelTo('2026-09-16 20:00:00'));
afterEach(fn () => $this->travelBack());

function qbAvailabilityFixture(): array
{
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['abbreviation' => 'SEA'])->id, 'away_team_id' => Team::factory()->create(['abbreviation' => 'ARI'])->id,
        'season' => 2026, 'season_type' => '2', 'week' => 2, 'game_date' => '2026-09-20', 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED']);
    $players = [];
    foreach (['Starter', 'Backup', 'Third'] as $index => $name) {
        $player = Player::factory()->create(['team_id' => $game->home_team_id, 'position' => 'QB', 'full_name' => $name.' Quarterback']);
        DepthChartEntry::create(['team_id' => $game->home_team_id, 'player_id' => $player->id, 'season' => 2026,
            'position_slot_key' => 'QB', 'position_code' => 'QB', 'espn_athlete_id' => $player->espn_id,
            'depth_rank' => $index + 1, 'slot_order' => 0, 'is_starter' => $index === 0, 'source_updated_at' => now()->subHour()]);
        $players[] = $player;
    }

    return [$game, ...$players];
}

function qbInjurySnapshot(Game $game, array $statuses, ?string $observedAt = null): PlayerInjurySnapshot
{
    $at = $observedAt ?? now()->subMinutes(30)->toDateTimeString();
    $snapshot = PlayerInjurySnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
        'espn_team_id' => $game->homeTeam->espn_id, 'provider' => 'espn', 'observed_at' => $at, 'source_updated_at' => $at,
        'payload_hash' => hash('sha256', json_encode($statuses).$at), 'entry_count' => count($statuses)]);
    foreach ($statuses as $id => $status) {
        $snapshot->entries()->create(['player_id' => $id, 'espn_athlete_id' => Player::findOrFail($id)->espn_id,
            'injury_key' => 'test-'.$id, 'status' => $status, 'observed_at' => $at, 'source_updated_at' => $at]);
    }

    return $snapshot;
}

function projectedQb(Game $game): ?array
{
    return (new ReflectionMethod(GeneratePredictionFromHistoricalElo::class, 'projectedQbContextFromDepthChart'))
        ->invoke(app(GeneratePredictionFromHistoricalElo::class), $game, $game->home_team_id);
}

it('selects the ranked backup and his own prior statistics for a ruled-out starter', function () {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    $snapshot = qbInjurySnapshot($game, [$starter->id => 'Out']);
    $prior = Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-10']);
    PlayerStat::create(['game_id' => $prior->id, 'team_id' => $game->home_team_id, 'player_id' => $backup->id,
        'passing_attempts' => 35, 'passing_yards' => 245]);
    $qb = projectedQb($game);
    expect($qb['qb_id'])->toBe($backup->id)->and($qb['prior_attempts'])->toBe(35)
        ->and($qb['prior_yards_per_attempt'])->toBe(7.0)
        ->and($qb['replaced_unavailable_qb_ids'])->toBe([$starter->id])
        ->and($qb['availability_exclusions'][0]['snapshot_uuid'])->toBe($snapshot->snapshot_uuid);
});

it('does not treat practice uncertainty as confirmed unavailability', function (string $status) {
    [$game, $starter] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => $status]);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
})->with(['Questionable', 'Doubtful', 'Probable', 'Day-To-Day', 'Limited', 'Did Not Participate', 'Active']);

it('excludes reserve and inactive quarterbacks', function (string $status) {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => $status]);
    expect(projectedQb($game)['qb_id'])->toBe($backup->id);
})->with(['Injured Reserve', 'IR', 'Inactive', 'Reserve/PUP', 'Suspended']);

it('skips an unavailable backup but never revives an injured starter when everyone is out', function () {
    [$game, $starter, $backup, $third] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => 'Out', $backup->id => 'Out']);
    expect(projectedQb($game)['qb_id'])->toBe($third->id);
    qbInjurySnapshot($game, [$starter->id => 'Out', $backup->id => 'Out', $third->id => 'Out'], now()->toDateTimeString());
    $context = (new ReflectionMethod(GeneratePredictionFromHistoricalElo::class, 'qbContextForGame'))
        ->invoke(app(GeneratePredictionFromHistoricalElo::class), $game, $game->home_team_id);
    expect($context['qb_id'])->toBeNull()->and($context['reason'])->toBe('no_available_depth_chart_qb');
});

it('holds ambiguous backup depth ranks rather than guessing', function () {
    [$game, $starter, $backup, $third] = qbAvailabilityFixture();
    DepthChartEntry::where('player_id', $third->id)->update(['depth_rank' => 2]);
    qbInjurySnapshot($game, [$starter->id => 'Out']);
    expect(projectedQb($game)['reason'])->toBe('ambiguous_available_depth_chart_qb');
});

it('uses the latest injury snapshot and does not carry a removed injury forward', function () {
    [$game, $starter] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => 'Out']);
    qbInjurySnapshot($game, [], now()->toDateTimeString());
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('ignores future injury information in a pregame preview', function () {
    [$game, $starter] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => 'Questionable']);
    qbInjurySnapshot($game, [$starter->id => 'Out'], now()->addDay()->toDateTimeString());
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('does not use post-kickoff injuries in historical reconstruction', function () {
    [$game, $starter] = qbAvailabilityFixture();
    $snapshot = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
        'espn_team_id' => $game->homeTeam->espn_id, 'season' => 2026, 'provider' => 'espn', 'observed_at' => now(),
        'payload_hash' => hash('sha256', 'depth'), 'entry_count' => 3]);
    foreach (DepthChartEntry::where('team_id', $game->home_team_id)->get() as $entry) {
        $snapshot->entries()->create([...$entry->only(['player_id', 'position_slot_key', 'position_code', 'espn_athlete_id', 'depth_rank', 'is_starter']), 'observed_at' => now()]);
    }
    qbInjurySnapshot($game, [$starter->id => 'Questionable']);
    qbInjurySnapshot($game, [$starter->id => 'Out'], '2026-09-21 12:00:00');
    $game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo('2026-09-25 12:00:00');
    config(['nfl.predictions.historical_profile' => 'full-historical']);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('supports current injury rows when no immutable snapshot exists', function () {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    PlayerInjury::create(['player_id' => $starter->id, 'team_id' => $game->home_team_id, 'injury_key' => 'out',
        'status' => 'Out', 'is_active' => true, 'injury_date' => now()->toDateString(), 'return_date' => '2026-09-27', 'source_updated_at' => now()]);
    expect(projectedQb($game)['qb_id'])->toBe($backup->id);
});

it('scopes injury dates and return dates to the target game', function () {
    [$game, $starter] = qbAvailabilityFixture();
    $snapshot = qbInjurySnapshot($game, [$starter->id => 'Out']);
    $snapshot->entries()->update(['return_date' => '2026-09-18']);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
    $snapshot->entries()->update(['return_date' => null, 'injury_date' => '2026-09-21']);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('keeps missing identity and missing backup history ineligible', function () {
    $service = app(RecommendationEligibility::class);
    $analysis = ['bet_classification' => 'bet', 'pro_signal_layer' => ['tier' => 'lean', 'market_scores' => ['spread' => ['tier' => 'lean']]]];
    expect($service->evaluate($analysis, ['qb_form' => ['enabled' => true, 'reason' => 'missing_game_qb_identity']])['data_reasons'])->toContain('missing_quarterback_identity');
    expect($service->evaluate($analysis, ['qb_form' => ['reason' => 'insufficient_prior_attempts']])['data_reasons'])->toContain('missing_quarterback_history');
});

it('invalidates research when QB identity changes even if rounded predictions do not', function () {
    $candidate = ['predicted_spread' => 3, 'predicted_total' => 44, 'win_probability' => .55, 'model_metadata' => ['qb_form' => ['away' => ['qb_id' => 1, 'qb_name' => 'Starter']]]];
    $before = app(ResearchPipeline::class)->candidateContextHash($candidate);
    $candidate['model_metadata']['qb_form']['away'] = ['qb_id' => 2, 'qb_name' => 'Backup'];
    expect(app(ResearchPipeline::class)->candidateContextHash($candidate))->not->toBe($before);
});

it('selects the available nflverse backup and never falls back to the excluded starter', function () {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    DepthChartEntry::where('team_id', $game->home_team_id)->delete();
    foreach ([[$starter, 1], [$backup, 2]] as [$player, $rank]) {
        DB::table('nflverse_depth_charts')->insert(['nflverse_depth_chart_key' => hash('sha256', $player->id),
            'season' => 2026, 'week' => 2, 'team' => $game->homeTeam->abbreviation, 'position' => 'QB',
            'gsis_id' => '00-'.$player->id, 'full_name' => $player->full_name, 'depth_rank' => $rank,
            'source_updated_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    }
    qbInjurySnapshot($game, [$starter->id => 'Out']);
    expect(projectedQb($game)['qb_id'])->toBe('00-'.$backup->id);
    qbInjurySnapshot($game, [$starter->id => 'Out', $backup->id => 'Out'], now()->toDateTimeString());
    expect(projectedQb($game)['qb_id'])->toBeNull();
});

it('cannot resurrect a ruled-out quarterback through the prior-game fallback', function () {
    [$game, $starter] = qbAvailabilityFixture();
    DepthChartEntry::where('team_id', $game->home_team_id)->delete();
    $prior = Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-10']);
    PlayerStat::create(['game_id' => $prior->id, 'team_id' => $game->home_team_id, 'player_id' => $starter->id, 'passing_attempts' => 35]);
    qbInjurySnapshot($game, [$starter->id => 'Out']);
    $result = (new ReflectionMethod(GeneratePredictionFromHistoricalElo::class, 'qbContextForGame'))
        ->invoke(app(GeneratePredictionFromHistoricalElo::class), $game, $game->home_team_id);
    expect($result['qb_id'])->toBeNull()->and($result['reason'])->toBe('prior_game_qb_unavailable');
});

it('does not treat a previous short-term out designation as season-long unavailability', function () {
    [$game, $starter] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => 'Out'], '2026-09-01 12:00:00');
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('honors a newer verified clearance without letting older research override the injury feed', function () {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    qbInjurySnapshot($game, [$starter->id => 'Out']);
    $fact = ['team_id' => $game->home_team_id, 'player_id' => $starter->id, 'player_name' => $starter->full_name,
        'status' => 'Available', 'observed_at' => now()->toIso8601String(), 'published_at' => now()->subHour()->toIso8601String()];
    $game->setAttribute('research_availability', [$fact]);
    expect(projectedQb($game)['qb_id'])->toBe($backup->id);
    $fact['published_at'] = now()->toIso8601String();
    $game->setAttribute('research_availability', [$fact]);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});

it('uses current-week nflverse injuries without crossing season types', function () {
    [$game, $starter, $backup] = qbAvailabilityFixture();
    DB::table('nflverse_injuries')->insert(['nflverse_injury_key' => hash('sha256', 'injury'), 'season' => 2026,
        'week' => 2, 'season_type' => 'PRE', 'team' => 'SEA', 'position' => 'QB', 'full_name' => $starter->full_name,
        'report_status' => 'Out', 'source_updated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
    DB::table('nflverse_injuries')->update(['season_type' => 'REG']);
    expect(projectedQb($game)['qb_id'])->toBe($backup->id);
    DB::table('nflverse_injuries')->update(['source_updated_at' => now()->subDays(10)]);
    expect(projectedQb($game)['qb_id'])->toBe($starter->id);
});
