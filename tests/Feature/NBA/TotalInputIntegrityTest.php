<?php

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-24 12:00:00');
    Http::preventStrayRequests();
    config(['nba.prediction.true_epa.enabled' => false]);
    $this->home = Team::factory()->create();
    $this->away = Team::factory()->create();
    $this->target = Game::factory()->create([
        'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-24', 'game_time' => '22:00:00',
    ]);
});

function nbaTotalHistoryGame(Team $home, Team $away, array $attributes = [], bool $paired = true): Game
{
    $game = Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-22', 'game_time' => '23:00:00',
        'home_score' => 120, 'away_score' => 100, ...$attributes,
    ]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $home->id, 'points' => 120, 'possessions' => 100]);
    if ($paired) {
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $away->id, 'points' => 100, 'possessions' => 100]);
    }

    return $game;
}

function nbaTotalRecentForm(Team $team, Game $target): array
{
    $action = new GeneratePrediction;

    return (new ReflectionMethod($action, 'getRecentForm'))->invoke($action, $team, (int) $target->season, $target);
}

it('excludes preseason and all star games from regular season recent and venue inputs', function () {
    nbaTotalHistoryGame($this->home, $this->away);
    foreach ([1, 4] as $type) {
        $excluded = nbaTotalHistoryGame($this->home, $this->away, ['season_type' => $type, 'game_date' => '2026-10-23']);
        TeamStat::query()->where('game_id', $excluded->id)->where('team_id', $this->home->id)->update(['points' => 160]);
    }
    $action = new GeneratePrediction;
    $venue = (new ReflectionMethod($action, 'getVenueEfficiency'))->invoke($action, $this->home, 2027, 'home', $this->target);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(120.0)
        ->and($venue['off'])->toBe(120.0);
});

it('retains regular and postseason form for a playoff target while excluding preseason', function () {
    nbaTotalHistoryGame($this->home, $this->away, ['season_type' => 2]);
    nbaTotalHistoryGame($this->home, $this->away, ['season_type' => 3, 'game_date' => '2026-10-23']);
    $preseason = nbaTotalHistoryGame($this->home, $this->away, ['season_type' => 1, 'game_date' => '2026-10-23']);
    TeamStat::query()->where('game_id', $preseason->id)->where('team_id', $this->home->id)->update(['points' => 170]);
    $this->target->update(['season_type' => 3]);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(120.0);
});

it('includes a completed previous Central night game on the same UTC date and recognizes back to back rest', function () {
    nbaTotalHistoryGame($this->home, $this->away, ['game_date' => '2026-10-24', 'game_time' => '00:00:00']);
    $action = new GeneratePrediction;
    $rest = (new ReflectionMethod($action, 'getRestDays'))->invoke($action, $this->home, $this->target);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(120.0)
        ->and($rest)->toBe(1);
});

it('excludes games at or after target tipoff despite final status', function (string $time) {
    nbaTotalHistoryGame($this->home, $this->away, ['game_date' => '2026-10-24', 'game_time' => $time]);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(110.0);
})->with(['22:00:00', '23:00:00']);

it('uses canonical starts instead of contradictory raw dates and times', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2027, 'starts_at' => CarbonImmutable::parse('2026-10-24T00:00:00Z')->setTimezone(config('app.timezone'))]);
    nbaTotalHistoryGame($this->home, $this->away, ['sport_event_id' => $event->id, 'game_date' => '2026-10-26', 'game_time' => '23:00:00']);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(120.0);
    $action = new GeneratePrediction;
    expect((new ReflectionMethod($action, 'getRestDays'))->invoke($action, $this->home, $this->target))->toBe(1);
});

it('does not use canonical future games merely because their raw date is earlier', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2027, 'starts_at' => CarbonImmutable::parse('2026-10-25T00:00:00Z')->setTimezone(config('app.timezone'))]);
    nbaTotalHistoryGame($this->home, $this->away, ['sport_event_id' => $event->id]);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(110.0);
});

it('excludes unpaired and zero possession finals from recent and venue calculations', function (string $invalid) {
    $game = nbaTotalHistoryGame($this->home, $this->away, [], $invalid !== 'unpaired');
    if ($invalid === 'zero_possessions') {
        TeamStat::query()->where('game_id', $game->id)->where('team_id', $this->away->id)->update(['possessions' => 0]);
    }
    $action = new GeneratePrediction;
    $venue = (new ReflectionMethod($action, 'getVenueEfficiency'))->invoke($action, $this->home, 2027, 'home', $this->target);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(110.0)
        ->and($venue['off'])->toBe(110.0);
})->with(['unpaired', 'zero_possessions']);

it('fills the recent window with valid paired games rather than allowing incomplete rows to displace them', function () {
    nbaTotalHistoryGame($this->home, $this->away, ['game_date' => '2026-10-10']);
    for ($i = 0; $i < 11; $i++) {
        nbaTotalHistoryGame($this->home, $this->away, ['game_date' => '2026-10-23'], false);
    }
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(120.0);
});

it('does not invent a tipoff for date only history without a canonical start', function () {
    nbaTotalHistoryGame($this->home, $this->away, ['game_time' => '']);
    expect(nbaTotalRecentForm($this->home, $this->target)['off_eff'])->toBe(110.0);
});

it('counts Central calendar rest days across the fall daylight saving transition', function () {
    $this->target->update(['game_date' => '2026-11-01', 'game_time' => '23:00:00']);
    nbaTotalHistoryGame($this->home, $this->away, ['game_date' => '2026-11-01', 'game_time' => '00:00:00']);
    $action = new GeneratePrediction;
    expect((new ReflectionMethod($action, 'getRestDays'))->invoke($action, $this->home, $this->target))->toBe(1);
});

it('removes the market boundary jump while preserving the full blend at high totals', function () {
    config(['nba.prediction.total_recent_efficiency_weight' => 0, 'nba.prediction.total_venue_efficiency_weight' => 0]);
    $metrics = new TeamMetric(['offensive_efficiency' => 110, 'defensive_efficiency' => 110, 'tempo' => 100]);
    $action = new GeneratePrediction;
    $total = new ReflectionMethod($action, 'calculatePredictedTotal');
    $projections = [];
    foreach ([228.5, 229, 229.5, 239, 241.5] as $line) {
        $this->target->odds_data = ['bookmakers' => [['markets' => [['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => $line]]]]]]];
        $projections[] = $total->invoke($action, $metrics, $metrics, $this->target);
    }
    expect($projections[0])->toBe(222.0)->and($projections[1])->toBe(222.0)
        ->and($projections[2] - $projections[0])->toBeLessThan(0.5)
        ->and($projections[3])->toBe(233.6)->and($projections[4])->toBe(235.3);
});
