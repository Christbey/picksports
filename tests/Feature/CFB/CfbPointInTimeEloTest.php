<?php

use App\Actions\CFB\CalculateElo;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\Predictions\CfbPointInTimeElo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('selects observed prior-game versioned ratings instead of mutable team Elo or later result rows', function () {
    $team = Team::factory()->create(['elo_rating' => 1900]);
    $initial = DB::table('cfb_elo_season_initializations')->insertGetId(['team_id' => $team->id, 'season' => 2026,
        'model_version' => CalculateElo::MODEL_VERSION, 'prior_rating' => 1500, 'initial_rating' => 1500, 'regression_factor' => 0.3,
        'active_slot' => 1, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01']);
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-01']);
    $row = EloRating::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => 2026, 'week' => 1,
        'season_type' => 2, 'elo_rating' => 1520, 'elo_change' => 20, 'model_version' => CalculateElo::MODEL_VERSION, 'season_initialization_id' => $initial]);
    DB::table('cfb_elo_ratings')->where('id', $row->id)->update(['created_at' => '2026-09-01 05:00:00', 'updated_at' => '2026-09-01 05:00:00']);
    $service = new CfbPointInTimeElo;
    $result = $service->forTeam($team->id, 2026, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-03'));
    expect($result['rating'])->toBe(1520.0)->and($result['evidence']['history_id'])->toBe($row->id)
        ->and($result['evidence']['qualified'])->toBeTrue();
    DB::table('cfb_elo_ratings')->where('id', $row->id)->update(['updated_at' => '2026-09-04', 'rebuilt_at' => '2026-09-04']);
    $result = $service->forTeam($team->id, 2026, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-03'));
    expect($result['rating'])->toBe(1500.0)->and($result['evidence']['history_id'])->toBeNull()
        ->and($result['evidence']['source_kind'])->toBe('season_initialization');
});

it('uses an explicitly unqualified default when no observed history or initialization exists', function () {
    $team = Team::factory()->create(['elo_rating' => 2100]);
    $result = (new CfbPointInTimeElo)->forTeam($team->id, 2026, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-03'));
    expect($result['rating'])->toBe(1500.0)->and($result['evidence']['qualified'])->toBeFalse();
});

it('derives an auditable preseason baseline from only the immediately preceding verified season', function () {
    $team = Team::factory()->create(['elo_rating' => 2200]);
    $initial = DB::table('cfb_elo_season_initializations')->insertGetId(['team_id' => $team->id, 'season' => 2025,
        'model_version' => CalculateElo::MODEL_VERSION, 'prior_rating' => 1500, 'initial_rating' => 1500, 'regression_factor' => 0.3,
        'active_slot' => 1, 'created_at' => '2025-08-01', 'updated_at' => '2025-08-01']);
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2025, 'status' => 'STATUS_FINAL', 'game_date' => '2025-12-01']);
    $row = EloRating::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => 2025, 'week' => 14,
        'season_type' => 'regular', 'elo_rating' => 1600, 'elo_change' => 20, 'model_version' => CalculateElo::MODEL_VERSION,
        'season_initialization_id' => $initial, 'result_fingerprint' => app(CalculateElo::class)->fingerprint($game->fresh())]);
    DB::table('cfb_elo_ratings')->where('id', $row->id)->update(['created_at' => '2025-12-02', 'updated_at' => '2025-12-02']);
    $service = new CfbPointInTimeElo;
    $capture = CarbonImmutable::parse('2026-08-25');
    $cutoff = CarbonImmutable::parse('2026-08-26');
    $result = $service->forTeam($team->id, 2026, $capture, $cutoff);
    expect($result['rating'])->toBe(1570.0)->and($result['evidence']['qualified'])->toBeTrue()
        ->and($result['evidence']['source_kind'])->toBe('derived_season_initialization')
        ->and($result['evidence']['source_history_id'])->toBe($row->id)
        ->and($result['evidence']['season_initialization_id'])->toBeNull()
        ->and(DB::table('cfb_elo_season_initializations')->where('season', 2026)->count())->toBe(0);
    expect($service->forTeam($team->id, 2027, $capture->addYear(), $cutoff->addYear())['evidence']['qualified'])->toBeFalse();
    config()->set('cfb.elo.offseason_regression_factor', 0.4);
    expect($service->forTeam($team->id, 2026, $capture, $cutoff)['evidence']['qualified'])->toBeFalse();
    config()->set('cfb.elo.offseason_regression_factor', 0.3);
    DB::table('cfb_elo_ratings')->where('id', $row->id)->update(['rebuilt_at' => '2026-09-01']);
    expect($service->forTeam($team->id, 2026, $capture, $cutoff)['evidence']['qualified'])->toBeFalse();
    DB::table('cfb_elo_ratings')->where('id', $row->id)->update(['rebuilt_at' => null, 'model_version' => null]);
    expect($service->forTeam($team->id, 2026, $capture, $cutoff)['evidence']['qualified'])->toBeFalse();
});
