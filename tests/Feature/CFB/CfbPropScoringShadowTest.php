<?php

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Services\CFB\CfbPropScoringShadowRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $team = Team::factory()->create();
    $away = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $away->id,
        'season' => 2026, 'game_date' => '2026-10-10', 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED']);
    $this->player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '90001234', 'full_name' => 'Test Quarterback']);
    $this->prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'player_name' => $this->player->full_name, 'market' => 'player_pass_yds', 'bookmaker' => 'fanduel',
        'line' => 200.5, 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now(), 'is_current' => true]);
    $this->prop->refresh();
    $this->analysis = ['confidence' => 65, 'recommendation' => 'Over', 'model_over_probability' => 60];
});

it('preserves the first pregame analysis and records changed analyses separately', function () {
    $recorder = app(CfbPropScoringShadowRecorder::class);
    $recorder->record($this->prop, $this->analysis);
    $recorder->record($this->prop, $this->analysis);
    expect(DB::table('cfb_prop_signal_snapshots')->count())->toBe(1);
    $recorder->record($this->prop, [...$this->analysis, 'confidence' => 70]);
    $saved = DB::table('cfb_prop_signal_snapshots')->orderBy('id')->get();
    expect($saved)->toHaveCount(2)
        ->and(data_get(json_decode($saved[0]->payload, true), 'analysis.confidence'))->toBe(65);
});

it('does not certify late or future quote observations', function () {
    $recorder = app(CfbPropScoringShadowRecorder::class);
    $this->prop->fetched_at = now()->addMinute();
    $recorder->record($this->prop, $this->analysis);
    $this->prop->fetched_at = now();
    $this->travelTo(now()->addHours(5));
    $recorder->record($this->prop, $this->analysis);
    expect(DB::table('cfb_prop_signal_snapshots')->count())->toBe(0);
});

it('evaluates the preserved line even after the mutable quote changes', function () {
    app(CfbPropScoringShadowRecorder::class)->record($this->prop, $this->analysis);
    $this->prop->update(['line' => 300.5, 'confidence_score' => 32]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'team_id' => $this->player->team_id, 'passing_yards' => 250]);
    $this->travelTo(now()->addHours(5));
    expect(Artisan::call('cfb:report-prop-scoring-validation'))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['markets']['player_pass_yds']['shadow_board_record']['wins'])->toBe(1)
        ->and($report['recommendations_approved'])->toBeFalse();
});

it('compares relative variability only when the original score is not clipped', function () {
    $analysis = ['confidence' => 55, 'confidence_decomposition' => [
        'stat_summary' => ['season_avg' => 286, 'consistency' => ['std_dev' => 32.2]],
        'uncertainty_penalty' => 11, 'confidence_cap' => 82,
        'cfb_context' => ['evidence' => ['windows' => ['this_season' => ['games' => 6]]]],
    ]];
    $service = app(CfbPropScoringShadowRecorder::class);
    $candidate = $service->candidate($analysis);
    expect($candidate['candidate_penalty'])->toBe(1)
        ->and($candidate['candidate_score'])->toBe(75)
        ->and($candidate['approved'])->toBeFalse();
    $analysis['confidence'] = 32;
    expect($service->candidate($analysis)['status'])->toBe('not_comparable');
});
