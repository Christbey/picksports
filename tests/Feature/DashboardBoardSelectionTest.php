<?php

use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\Support\CanonicalReadFixture;

beforeEach(function () {
    $this->withoutVite();
    config(['sports.business_timezone' => 'America/Chicago', 'app.timezone' => 'UTC', 'subscriptions.enforce_tiers' => false]);
});

/** Create the same stored schedule shape used by sport boards. */
function selectionGame(string $sport, string $date, ?string $time, bool $canonical, bool $published = true): Model
{
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $predictionClass = "App\\Models\\{$namespace}\\Prediction";
    $startsAt = Carbon::parse($date.' '.($time ?? '18:00:00'), 'UTC');
    $event = $canonical ? SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => $startsAt]) : null;
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event?->id,
        'home_team_id' => $teamClass::factory(),
        'away_team_id' => $teamClass::factory(),
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => $date,
        'game_time' => $time,
    ]);
    // Preserve split UTC date/time rather than a model's datetime serialization.
    DB::table($game->getTable())->where('id', $game->id)->update(['game_date' => $date, 'game_time' => $time]);

    if ($canonical) {
        $run = CanonicalReadFixture::run($event, ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]]);
        CanonicalPrediction::factory()->create([
            'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id, 'output_hash' => $run->output_hash, 'generated_at' => $run->inputSnapshot->captured_at,
            'phase' => 'pregame', 'publication_state' => $published ? 'published' : 'draft',
            'published_at' => $published ? $startsAt->copy()->subDay() : null,
            'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
        ]);
    } elseif ($published) {
        $attributes = [
            'game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
            'home_elo' => 1510, 'away_elo' => 1490, 'home_team_elo' => 1510, 'away_team_elo' => 1490,
            'predicted_spread' => 2.5, 'predicted_total' => 45.5, 'win_probability' => 0.6, 'confidence_score' => 60,
        ];
        $predictionClass::query()->create(array_intersect_key($attributes, array_flip(Schema::getColumnListing((new $predictionClass)->getTable()))));
    }

    return $game;
}

it('selects identical dashboard and board games across local midnight and DST', function (string $sport, bool $canonical, string $date) {
    config(["prediction_lifecycle.canonical_reads.{$sport}" => $canonical]);
    // UTC has already rolled over, while the business date is still the requested date.
    $this->travelTo(Carbon::parse($date.' 23:30:00', 'America/Chicago'));
    $window = app(SportsDateWindowService::class)->forDate($date);
    $expected = [];
    foreach ([$window->utcStart->subSecond(), $window->utcStart, $window->utcStart->addHours(12), $window->utcEnd->startOfSecond(), $window->utcEnd->addSecond()->startOfSecond()] as $index => $start) {
        $game = selectionGame($sport, $start->toDateString(), $start->toTimeString(), $canonical);
        if (in_array($index, [0, 3], true)) {
            DB::table($game->getTable())->where('id', $game->id)->update(['game_date' => $start->toDateTimeString()]);
        }
        if (in_array($index, [1, 2, 3], true)) {
            $expected[] = $game->id;
        }
    }
    selectionGame($sport, $date, '18:30:00', $canonical, false);
    // Canonical mode must not fill gaps with legacy predictions.
    if ($canonical) {
        selectionGame($sport, $date, '18:45:00', false);
    }
    $this->actingAs(User::factory()->create());
    $board = $this->getJson("/api/v2/sports/{$sport}/predictions?".http_build_query([
        'season' => 2026, 'from_date' => $date, 'to_date' => $date, 'per_page' => 2,
    ]))->assertOk();
    $boardIds = collect($board->json('data'))->pluck('game_id');
    for ($page = 2; $page <= $board->json('meta.pagination.last_page'); $page++) {
        $rows = $this->getJson("/api/v2/sports/{$sport}/predictions?".http_build_query([
            'season' => 2026, 'from_date' => $date, 'to_date' => $date, 'per_page' => 2, 'page' => $page,
        ]))->assertOk()->json('data');
        $boardIds = $boardIds->concat(collect($rows)->pluck('game_id'));
    }
    $dashboard = $this->get(route('dashboard'))->assertOk();
    $dashboardIds = collect($dashboard->inertiaProps('sports'))->firstWhere('name', strtoupper($sport))['predictions'];
    expect(collect($dashboardIds)->pluck('game_id')->all())->toEqualCanonicalizing($expected)
        ->and($boardIds->all())->toEqualCanonicalizing($expected)
        ->and($dashboard->inertiaProps('stats.total_predictions_today'))->toBe(count($expected));
    $dates = $this->getJson("/api/v2/sports/{$sport}/predictions/available-dates?season=2026")->assertOk()->json('data');
    expect($dates)->toBe([$window->localStart->subDay()->toDateString(), $date, $window->localStart->addDay()->toDateString()]);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'])
    ->with(['legacy' => false, 'canonical' => true])
    ->with(['summer' => '2026-10-10', 'fall DST transition' => '2026-11-01', 'spring DST transition' => '2026-03-08']);

it('does not truncate the dashboard slate differently from the board for a limited tier', function () {
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => [], 'prediction_lifecycle.canonical_reads.nba' => false]);
    SubscriptionTier::factory()->create(['name' => 'Free', 'slug' => 'free', 'is_default' => true, 'is_active' => true, 'features' => ['predictions_per_day' => 1, 'api_access' => true, 'sports_access' => ['NBA']]]);
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'America/Chicago'));
    $expected = collect(['17:00:00', '18:00:00', '19:00:00'])->map(fn (string $time) => selectionGame('nba', '2026-10-10', $time, false)->id);
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('view-nba-predictions', 'web'));
    $this->actingAs($user);
    $board = $this->getJson('/api/v2/sports/nba/predictions?from_date=2026-10-10&to_date=2026-10-10')->assertOk();
    $dashboard = $this->get(route('dashboard'))->assertOk();
    expect(collect($dashboard->inertiaProps('sports.0.predictions'))->pluck('game_id')->all())->toEqualCanonicalizing($expected->all())
        ->and(collect($board->json('data'))->pluck('game_id')->all())->toEqualCanonicalizing($expected->all());
});

it('excludes unresolved CBB matchups from both daily slates and available dates', function () {
    config(['prediction_lifecycle.canonical_reads.cbb' => false]);
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'America/Chicago'));
    $known = selectionGame('cbb', '2026-10-10', '18:00:00', false);
    $placeholder = selectionGame('cbb', '2026-10-10', '19:00:00', false);
    $placeholder->homeTeam->update(['school' => ' TBD ']);
    $futurePlaceholder = selectionGame('cbb', '2026-10-11', '18:00:00', false);
    $futurePlaceholder->update(['espn_event_id' => 'placeholder:future']);
    $this->actingAs(User::factory()->create());
    $board = $this->getJson('/api/v2/sports/cbb/predictions?from_date=2026-10-10&to_date=2026-10-10')->assertOk();
    $dashboard = $this->get(route('dashboard'))->assertOk();
    expect(collect($board->json('data'))->pluck('game_id')->all())->toBe([$known->id])
        ->and(collect($dashboard->inertiaProps('sports.0.predictions'))->pluck('game_id')->all())->toBe([$known->id]);
    $this->getJson('/api/v2/sports/cbb/predictions/available-dates?season=2026')->assertOk()->assertExactJsonStructure(['data', 'meta'])->assertJsonPath('data', ['2026-10-10']);
});

it('uses a local calendar date for schedules without a kickoff and UTC for known timestamps', function () {
    $dates = app(SportsDateWindowService::class);
    expect($dates->gameDateForDisplay('2026-10-10', null))->toBe('2026-10-10')
        ->and($dates->gameDateForDisplay('2026-10-10 00:00:00', null))->toBe('2026-10-10')
        ->and($dates->gameDateForDisplay('2026-10-11 04:30:00', null))->toBe('2026-10-10')
        ->and($dates->gameDateForDisplay(null, null))->toBeNull();
});
