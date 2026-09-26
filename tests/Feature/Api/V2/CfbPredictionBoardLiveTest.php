<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();
    config(['prediction_lifecycle.canonical_reads.cfb' => true, 'subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subHour()]);
    $this->game = Game::factory()->create([
        'sport_event_id' => $event->id, 'status' => 'STATUS_IN_PROGRESS',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => now()->toDateString(), 'season' => 2026,
        'period' => 2, 'game_clock' => '05:00', 'home_score' => 14, 'away_score' => 7,
    ]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'status' => 'succeeded']);
    $this->prediction = CanonicalPrediction::factory()->create([
        'sport_event_id' => $event->id, 'sport' => 'cfb', 'phase' => 'pregame',
        'publication_state' => 'draft', 'published_at' => now()->subHours(2),
        'calculation_run_id' => $run->id,
    ]);
    foreach ([['moneyline', 'home', null, .65], ['spread', 'home', -7.5, null], ['total', 'combined', 48.5, null]] as [$type, $selection, $line, $probability]) {
        PredictionMarket::create(['prediction_id' => $this->prediction->id, 'market_type' => $type,
            'selection' => $selection, 'projected_line' => $line, 'probability' => $probability]);
    }
    $this->prediction->update(['publication_state' => 'published']);
    $this->snapshot = fn ($attributes = []) => LivePredictionSnapshot::create([
        'game_id' => $this->game->id, 'state_hash' => hash('sha256', fake()->uuid()),
        'source' => 'scoreboard', 'status' => 'live', 'pregame' => [], 'state' => [],
        'markets' => [], 'props' => [],
        'projection' => ['spread' => 12.5, 'total' => 52, 'home_win_probability' => .81, 'seconds_remaining' => 2100],
        'observed_at' => now(), ...$attributes,
    ]);
});

test('canonical CFB board overlays the latest live snapshot without changing pregame values', function () {
    ($this->snapshot)(['projection' => ['total' => 99]]);
    ($this->snapshot)();
    $before = $this->prediction->fresh()->getAttributes();
    $this->getJson('/api/v2/sports/cfb/predictions')->assertOk()
        ->assertJsonPath('data.0.live_status', 'live')
        ->assertJsonPath('data.0.live_predicted_total', 52)
        ->assertJsonPath('data.0.live_predicted_spread', 12.5)
        ->assertJsonPath('data.0.live_win_probability', .81)
        ->assertJsonPath('data.0.live_seconds_remaining', 2100)
        ->assertJsonPath('data.0.game.period', 2)
        ->assertJsonPath('data.0.game.clock', '05:00')
        ->assertJsonPath('data.0.predicted_total', 48.5)
        ->assertJsonPath('data.0.predicted_spread', -7.5)
        ->assertJsonPath('data.0.win_probability', .65);
    expect($this->prediction->fresh()->getAttributes())->toBe($before);
});

test('live board distinguishes missing first update unavailable baseline and stale data', function ($snapshotStatus, $age, $expected, $reason) {
    if ($snapshotStatus !== null) {
        ($this->snapshot)(['status' => $snapshotStatus, 'observed_at' => now()->subSeconds($age)]);
    }
    $this->getJson('/api/v2/sports/cfb/predictions')->assertOk()
        ->assertJsonPath('data.0.live_status', $expected)
        ->assertJsonPath('data.0.live_unavailable_reason', $reason)
        ->assertJsonPath('data.0.live_predicted_total', null)
        ->assertJsonPath('data.0.live_win_probability', null);
})->with([
    [null, 0, 'updating', null],
    ['missing_pregame', 0, 'unavailable', 'missing_baseline'],
    ['missing_clock', 0, 'unavailable', 'incomplete_game_state'],
    ['overtime_missing_possession', 0, 'unavailable', 'overtime'],
    ['live', 181, 'stale', null],
]);

test('live overlay is withheld for scheduled and finished games', function ($status) {
    ($this->snapshot)();
    $this->game->update(['status' => $status]);
    $this->getJson('/api/v2/sports/cfb/predictions')->assertOk()
        ->assertJsonPath('data.0.live_status', 'inactive')
        ->assertJsonPath('data.0.live_predicted_total', null)
        ->assertJsonPath('data.0.live_updated_at', null);
})->with(['STATUS_FINAL', 'STATUS_SCHEDULED']);

test('canonical CFB query eagerly loads a single latest snapshot per game', function () {
    ($this->snapshot)();
    ($this->snapshot)();
    $predictions = app(CanonicalSportPredictionQuery::class)->queryForSport('cfb')->get();
    DB::enableQueryLog();
    foreach ($predictions as $prediction) {
        $game = $prediction->sportEvent->cfbGame;
        expect($game->relationLoaded('latestLiveSnapshot'))->toBeTrue()
            ->and($game->latestLiveSnapshot)->toBeInstanceOf(LivePredictionSnapshot::class);
    }
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});
