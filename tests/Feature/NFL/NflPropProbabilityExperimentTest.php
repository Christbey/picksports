<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflPropProbabilityExperiment;

beforeEach(function () {
    $this->travelTo('2026-10-25 10:00:00');
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $team->id, 'position' => 'WR']);
    foreach (['2026-09-20', '2026-09-27', '2026-10-04', '2026-10-11'] as $date) {
        $g = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => $date, 'game_time' => '17:00:00']);
        PlayerStat::factory()->create(['game_id' => $g->id, 'player_id' => $this->player->id, 'team_id' => $team->id, 'receptions' => 6, 'receiving_targets' => 8, 'receiving_yards' => 60]);
    }
    $this->game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-26', 'game_time' => '00:20:00']);
    $this->prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id, 'player_name' => $this->player->full_name,
        'market' => 'player_receptions', 'line' => 4.5, 'over_price' => -110, 'under_price' => -110, 'bookmaker' => 'draftkings', 'fetched_at' => now()]);
    $this->service = app(NflPropProbabilityExperiment::class);
});

it('freezes a probability before kickoff without changing published forecasts and keeps first capture', function () {
    $before = $this->prop->fresh()->getAttributes();
    expect($this->service->capture(2026, $this->game->id)['created'])->toBe(1);
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropProbabilityExperiment::POLICY)->sole();
    expect($snapshot->outputs['over_probability'])->toBeGreaterThan(0)
        ->and($snapshot->generated_at->lt($snapshot->game_start_at))->toBeTrue()
        ->and($snapshot->game_start_at->utc()->toIso8601String())->toBe('2026-10-26T00:20:00+00:00')
        ->and($snapshot->feature_hash)->toHaveLength(64)
        ->and($this->prop->fresh()->getAttributes())->toBe($before)
        ->and($this->service->capture(2026, $this->game->id)['created'])->toBe(0);
    $this->prop->update(['line' => 8.5]);
    expect($this->service->capture(2026, $this->game->id)['created'])->toBe(0)
        ->and($snapshot->fresh()->market_context['line'])->toBe(4.5);
    expect(fn () => $snapshot->update(['outputs' => []]))->toThrow(LogicException::class);
});

it('grades reported zero once and leaves missing actuals pending', function () {
    $this->service->capture(2026, $this->game->id);
    $this->travelTo('2026-10-27 10:00:00');
    $this->game->update(['status' => 'STATUS_FINAL']);
    expect($this->service->grade(2026)['pending_missing_actual'])->toBe(1);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->player->team_id, 'player_id' => $this->player->id, 'receptions' => 0, 'receiving_yards' => 0, 'receiving_targets' => 1]);
    expect($this->service->grade(2026)['graded'])->toBe(1)
        ->and($this->service->grade(2026)['graded'])->toBe(0)
        ->and(PredictionEvaluation::sole()->actuals['value'])->toBe(0);
    $group = $this->service->monitoring(2026)['groups'][0];
    expect($group['graded'])->toBe(1)->and($group['pending'])->toBe(0)->and($group['brier'])->toBeGreaterThan(0);
});

it('does not create new forecasts after kickoff or from expired quotes', function () {
    $this->prop->update(['fetched_at' => now()->subDays(3)]);
    expect($this->service->capture(2026, $this->game->id)['created'])->toBe(0);
    $this->prop->update(['fetched_at' => now()]);
    $this->travelTo('2026-10-27 10:00:00');
    expect($this->service->capture(2026, $this->game->id)['created'])->toBe(0);
});

it('canonicalizes harmless database floating point round trip differences for integrity checks', function () {
    $a = ['mean' => 1.234567891234];
    $b = ['mean' => 1.234567891235];
    expect(NflPropProbabilityExperiment::fingerprint($a, [], []))->toBe(NflPropProbabilityExperiment::fingerprint($b, [], []))
        ->and(NflPropProbabilityExperiment::fingerprint(['mean' => 2.0], [], []))->not->toBe(NflPropProbabilityExperiment::fingerprint($a, [], []));
});
