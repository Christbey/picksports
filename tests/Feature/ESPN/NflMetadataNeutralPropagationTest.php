<?php

use App\Actions\NFL\GenerateCanonicalPrediction;
use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\SportEvent;
use App\Services\ESPN\NFL\EspnService;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;
use App\Services\NFL\Predictions\NflInputSnapshotBuilder;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('propagates a confirmed NFL neutral-site change to its canonical current event', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T16:00:00Z'));
    Http::preventStrayRequests();
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => 2,
        'game_date' => '2026-11-28', 'game_time' => '12:00:00', 'status' => 'STATUS_SCHEDULED', 'neutral_site' => false,
        'venue_name' => 'Original venue', 'venue_city' => 'Original city']);
    $event = app(SportEventIdentitySynchronizer::class)->sync('nfl', $game);
    expect($event->fresh()->neutral_site)->toBeFalse()->and($game->fresh()->sport_event_id)->toBe($event->id);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn([
        'header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => 2026], 'competitions' => [[
            'neutralSite' => true, 'competitors' => [
                ['homeAway' => 'home', 'team' => ['id' => (string) $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => (string) $away->espn_id]],
            ],
        ]]], 'gameInfo' => ['venue' => ['fullName' => 'Observed neutral venue', 'address' => ['city' => 'Observed city']]],
    ]);
    app()->instance(EspnService::class, $service);
    expect(Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]))->toBe(0)
        ->and((bool) $game->fresh()->neutral_site)->toBeTrue()->and($event->fresh()->neutral_site)->toBeTrue();
});

function nflMetadataCanonicalFixture(string $seasonType = '2'): array
{
    $startsAt = now()->addDay()->utc()->setTime(18, 0);
    $event = SportEvent::factory()->create([
        'sport' => 'nfl',
        'season' => 2026,
        'season_type' => $seasonType,
        'week' => 2,
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);
    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'elo_rating' => 1620,
    ]);
    $away = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'elo_rating' => 1440,
    ]);

    foreach ([[$home, 32, 18, 14, 2], [$away, 19, 29, -10, -1]] as [$team, $scored, $allowed, $rating, $turnovers]) {
        TeamMetric::query()->create([
            'team_id' => $team->getKey(),
            'season' => 2026,
            'season_type' => $seasonType === '3' ? '2' : $seasonType,
            'wins' => 9,
            'losses' => 3,
            'offensive_rating' => 110,
            'defensive_rating' => 95,
            'net_rating' => $rating,
            'points_per_game' => $scored,
            'points_allowed_per_game' => $allowed,
            'turnover_differential' => $turnovers,
            'recent_form_rating' => $rating / 2,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'injury_total_adjustment' => 0,
            'rest_travel_fatigue' => 0,
            'predictive_rating' => $rating,
            'offensive_true_epa_per_play' => $team->is($home) ? 0.12 : 0.04,
            'defensive_true_epa_per_play' => $team->is($home) ? -0.02 : 0.01,
            'net_true_epa_per_play' => $team->is($home) ? 0.14 : 0.03,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => $home->getKey(),
        'away_team_id' => $away->getKey(),
        'season' => 2026,
        'season_type' => $seasonType,
        'week' => 2,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
        'game_date' => $startsAt,
        'game_time' => $startsAt->format('H:i:s'),
        'home_score' => null,
        'away_score' => null,
    ]);

    $event = app(SportEventIdentitySynchronizer::class)->sync('nfl', $game);

    return compact('event', 'game', 'home', 'away');
}

function recordNflMetadataMarketSnapshot(
    array $fixture,
    float $homeLine = -3.0,
    float $total = 44.5,
    ?string $providerObservedAt = null,
): void {
    $oddsData = [
        'home_team' => 'Chicago Bears',
        'away_team' => 'Detroit Lions',
        'bookmakers' => [[
            'key' => 'testbook',
            'title' => 'Test Book',
            ...($providerObservedAt === null ? [] : ['last_update' => $providerObservedAt]),
            'markets' => [
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Chicago Bears', 'price' => -150],
                        ['name' => 'Detroit Lions', 'price' => 130],
                    ],
                ],
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Chicago Bears', 'point' => $homeLine, 'price' => -110],
                        ['name' => 'Detroit Lions', 'point' => -$homeLine, 'price' => -110],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => $total, 'price' => -110],
                        ['name' => 'Under', 'point' => $total, 'price' => -110],
                    ],
                ],
            ],
        ]],
    ];

    $fixture['game']->update([
        'odds_data' => $oddsData,
        'odds_updated_at' => now()->subMinute(),
    ]);
    app(GameOddsSnapshotRecorder::class)->record(
        'nfl',
        $fixture['game'],
        ['id' => 'nfl-test-market', 'commence_time' => $fixture['event']->starts_at->toIso8601String()],
        $oddsData,
        Carbon::parse(now()->subMinute()),
        'test',
    );
}

it('feeds the corrected NFL neutral site to fresh legacy and canonical inputs while retaining prior forecast bytes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T16:00:00Z'));
    Http::preventStrayRequests();
    $fixture = nflMetadataCanonicalFixture();
    recordNflMetadataMarketSnapshot($fixture);
    $release = app(NflCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinutes(2)->toImmutable());
    $game = $fixture['game'];
    $prediction = app(GenerateCanonicalPrediction::class)->execute($game->fresh());
    $frozen = $prediction->calculationRun->inputSnapshot;
    expect(data_get($frozen->inputs, 'event.neutral_site'))->toBeFalse();
    $rows = [(array) DB::table($prediction->getTable())->where('id', $prediction->id)->first(),
        (array) DB::table($frozen->getTable())->where('id', $frozen->id)->first()];
    $legacy = new class extends GeneratePredictionFromHistoricalElo
    {
        public array $observedRatings = [];

        protected function calculateWinProbability(float $ratingA, float $ratingB): float
        {
            $this->observedRatings[] = [$ratingA, $ratingB];

            return parent::calculateWinProbability($ratingA, $ratingB);
        }
    };
    expect($legacy->preview($game->fresh()))->toBeArray();
    $homeWithField = $legacy->observedRatings[0][0];
    $payload = ['header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => 2026], 'competitions' => [[
        'neutralSite' => true, 'competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => (string) $fixture['home']->espn_id]],
            ['homeAway' => 'away', 'team' => ['id' => (string) $fixture['away']->espn_id]],
        ],
    ]]], 'gameInfo' => ['venue' => ['fullName' => 'Observed neutral stadium', 'address' => ['city' => 'Observed city']]]];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn($payload);
    app()->instance(EspnService::class, $service);
    expect(Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]))->toBe(0);
    expect($fixture['event']->fresh()->neutral_site)->toBeTrue()->and((bool) $game->fresh()->neutral_site)->toBeTrue();
    $legacy->observedRatings = [];
    expect($legacy->preview($game->fresh()))->toBeArray();
    expect($homeWithField - $legacy->observedRatings[0][0])->toBe((float) config('nfl.elo.home_field_advantage'));
    $fresh = app(NflInputSnapshotBuilder::class)->build($fixture['event']->fresh(), CalculationReleaseData::fromModel($release));
    expect(data_get($fresh->inputs, 'event.neutral_site'))->toBeTrue();
    expect([(array) DB::table($prediction->getTable())->where('id', $prediction->id)->first(),
        (array) DB::table($frozen->getTable())->where('id', $frozen->id)->first()])->toBe($rows);
    expect($prediction->newQuery()->count())->toBe(1)->and($frozen->newQuery()->count())->toBe(1);
});

it('requires an observed boolean neutral flag while preserving absent and null partial summaries', function (string $kind) {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T16:00:00Z'));
    Http::preventStrayRequests();
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $initial = $kind !== 'string';
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => 2,
        'game_date' => '2026-11-28', 'game_time' => '12:00:00', 'status' => 'STATUS_SCHEDULED', 'neutral_site' => $initial,
        'venue_name' => 'Original venue', 'venue_city' => 'Original city', 'home_score' => 0, 'away_score' => 0]);
    $event = app(SportEventIdentitySynchronizer::class)->sync('nfl', $game);
    $before = [(array) DB::table('nfl_games')->where('id', $game->id)->first(), (array) DB::table('sport_events')->where('id', $event->id)->first()];
    $competition = ['competitors' => [['homeAway' => 'home', 'team' => ['id' => (string) $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => (string) $away->espn_id]]]];
    if ($kind !== 'absent') {
        $competition['neutralSite'] = match ($kind) {
            'string' => 'false','null' => null,'false' => false
        };
    }
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn([
        'header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => 2026], 'competitions' => [$competition]],
        'gameInfo' => ['venue' => ['fullName' => 'Original venue', 'address' => ['city' => 'Original city']]],
    ]);
    app()->instance(EspnService::class, $service);
    $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    if ($kind === 'string') {
        expect([(array) DB::table('nfl_games')->where('id', $game->id)->first(), (array) DB::table('sport_events')->where('id', $event->id)->first()])->toBe($before)->and($code)->not->toBe(0);
    } else {
        $expected = $kind !== 'false';
        expect($code)->toBe(0)->and((bool) $game->fresh()->neutral_site)->toBe($expected)->and($event->fresh()->neutral_site)->toBe($expected);
    }
})->with(['string', 'null', 'absent', 'false']);
