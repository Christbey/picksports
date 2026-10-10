<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 9)->setTime(19, 0));
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addDay()]);
    $this->game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'week' => 6,
        'status' => 'STATUS_SCHEDULED', 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    foreach ([$this->game->home_team_id, $this->game->away_team_id] as $team) {
        TeamMetric::create(['team_id' => $team, 'season' => 2026, 'calculation_date' => now()->toDateString()]);
    }
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'captured_at' => now(), 'cutoff_at' => $event->starts_at, 'pregame_safety_status' => 'verified']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
        'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded']);
    $this->prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'calculation_run_id' => $run->id,
        'generated_at' => now(), 'published_at' => now()]);
    $this->prediction->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7]);
    $this->prediction->update(['publication_state' => 'published']);
});

it('passes only when the whole upcoming weekly board has fresh forecasts', function () {
    expect(Artisan::call('cfb:audit-forecast-freshness', ['--season' => 2026, '--week' => 6]))->toBe(0);
    $report = json_decode(Artisan::output(), true);
    expect($report['ready'])->toBeTrue()->and($report['counts']['fresh'])->toBe(1);
    Game::factory()->create(['season' => 2026, 'week' => 6, 'status' => 'STATUS_SCHEDULED', 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    expect(Artisan::call('cfb:audit-forecast-freshness', ['--season' => 2026, '--week' => 6]))->toBe(2);
});

it('holds recent forecasts when current metrics were refreshed after their input capture', function () {
    $this->travel(1)->minutes();
    TeamMetric::where('team_id', $this->game->home_team_id)->firstOrFail()->touch();
    expect(Artisan::call('cfb:audit-forecast-freshness', ['--season' => 2026, '--week' => 6]))->toBe(2);
    $report = json_decode(Artisan::output(), true);
    expect($report['games'][0]['risk_flags'])->toContain('home_metric_newer_than_forecast');
});

it('holds aging forecasts and preserves forecasts after kickoff as archives', function () {
    $this->travel(7)->hours();
    expect(Artisan::call('cfb:audit-forecast-freshness', ['--season' => 2026, '--week' => 6]))->toBe(2);
    expect(json_decode(Artisan::output(), true)['games'][0]['risk_flags'])->toContain('stale_forecast');
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->game->sportEvent->update(['starts_at' => now()->subMinute()]);
    expect(Artisan::call('cfb:audit-forecast-freshness', ['--season' => 2026, '--week' => 6]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['games'][0]['freshness'])->toBe('archived_after_kickoff');
});
