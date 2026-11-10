<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Prediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserBet;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\PerformanceStatistics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function vueCanonicalFixture(string $sport, float $homeLine = 4.5, float $homeProbability = 0.65, array $metadata = []): CanonicalPrediction
{
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addHour()]);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'season' => 2026,
        'game_date' => now()->utc()->toDateString(), 'game_time' => now()->addHour()->utc()->toTimeString(),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'status' => 'STATUS_SCHEDULED',
    ]);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded',
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null, 'output_metadata' => $metadata,
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'selection' => 'home', 'probability' => $homeProbability]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => $homeLine]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 45.5]);

    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

    return $prediction;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $this->withoutVite();
});
afterEach(fn () => Carbon::setTestNow());

it('shows canonical forecasts on the dashboard without legacy predictions', function (string $sport) {
    config()->set("prediction_lifecycle.canonical_reads.{$sport}", true);
    $prediction = vueCanonicalFixture($sport);
    $game = $prediction->sportEvent->{$sport.'Game'};
    $game->homeTeam->update(['color' => '004c54']);
    $game->awayTeam->update(['color' => 'aa0000']);
    CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $prediction->sport_event_id,
        'calculation_run_id' => null, 'phase' => 'pregame',
        'publication_state' => 'superseded', 'published_at' => now()->subMinute(), 'revision' => 2,
    ]);
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('sports', 1)->where('sports.0.name', strtoupper($sport))
        ->has('sports.0.predictions', 1)->where('sports.0.predictions.0.id', $prediction->public_id)
        ->where('sports.0.predictions.0.predicted_spread', -4.5)
        ->where('sports.0.predictions.0.win_probability', 0.65)
        ->where('sports.0.predictions.0.home_color', '004c54')
        ->where('sports.0.predictions.0.away_color', 'aa0000')
        ->missing('sports.0.predictions.0.audit_context')
        ->missing('sports.0.predictions.0.evidence_windows')
        ->where('stats.total_predictions_today', 1));

    $context = app(SportContextResolver::class)->resolve($sport);
    $summaries = app(SportGameQuery::class)->featuredPredictionSummaries($context);
    expect($summaries->sole()->prediction->id)->toBe($prediction->public_id)
        ->and($summaries->sole()->prediction->predictedSpread)->toBe(4.5);
    $game = $prediction->sportEvent->{$sport.'Game'};
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'home_score' => 14, 'away_score' => 7]);
    $this->getJson('/api/v2/live-scoreboard')->assertOk()
        ->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.id', $prediction->public_id)
        ->assertJsonStructure(['data' => ['games' => [['audit_context']]]])
        ->assertJsonPath('data.games.0.predicted_spread', -4.5)
        ->assertJsonPath('data.games.0.is_live', true)->assertJsonPath('data.games.0.home_score', 14);
    $this->getJson('/api/v2/sports/'.$sport.'/games/'.$game->id)->assertOk()
        ->assertJsonPath('data.has_prediction', true);

})->with(['cfb', 'nfl', 'nba', 'cbb', 'wcbb', 'wnba', 'mlb']);

it('uses latest canonical evaluations for performance without counting revisions twice', function () {
    config()->set('prediction_lifecycle.canonical_reads.nba', true);
    $prediction = vueCanonicalFixture('nba');
    foreach ([1 => false, 2 => true] as $revision => $correct) {
        PredictionEvaluation::query()->create([
            'canonical_prediction_id' => $prediction->id, 'sport_event_id' => $prediction->sport_event_id,
            'sport' => 'nba', 'prediction_phase' => 'pregame', 'evaluation_revision' => $revision,
            'scoring_version' => 'test', 'evaluation_hash' => hash('sha256', (string) $revision),
            'actuals' => [], 'errors' => ['winner_correct' => $correct, 'spread_absolute_error' => 2.0, 'total_absolute_error' => 3.0],
            'evaluated_at' => now(),
        ]);
    }
    $stats = app(PerformanceStatistics::class);
    expect($stats->getOverallStats()['total_predictions'])->toBe(1)
        ->and($stats->getOverallStats()['winner_accuracy'])->toBe(100.0)
        ->and($stats->getSeasonToDate()['nba']['season'])->toBe(2026);
});

it('exports canonical predictions in the admin Vue page', function (string $sport) {
    config()->set("prediction_lifecycle.canonical_reads.{$sport}", true);
    $prediction = vueCanonicalFixture($sport);
    $this->actingAs(User::factory()->admin()->create())->get(route('settings.prop-exports', ['sport' => strtoupper($sport), 'tab' => 'predictions']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('predictions', 1)->where('predictions.0.id', $prediction->public_id)
        ->where('predictions.0.predicted_spread', 4.5)->has('predictionDates', 1)->has('predictionGames', 1));
})->with(['nba', 'nfl', 'mlb', 'cbb']);

it('saves and tracks canonical public IDs without mixing legacy numeric IDs', function () {
    $prediction = vueCanonicalFixture('nba');
    $user = User::factory()->create();
    UserBet::factory()->create(['user_id' => $user->id, 'prediction_id' => $prediction->id,
        'prediction_type' => Prediction::class, 'prediction_sport' => 'nba']);
    $this->actingAs($user)->postJson('/api/v2/user-bets', [
        'prediction_id' => $prediction->public_id, 'prediction_sport' => 'nba',
        'bet_amount' => 10, 'odds' => '-110', 'bet_type' => 'spread', 'selection_side' => 'home',
    ])->assertCreated()->assertJsonPath('data.prediction_id', $prediction->public_id)
        ->assertJsonPath('data.prediction_reference.event_id', $prediction->sportEvent->public_id);
    $this->getJson('/api/v2/user-bets?'.http_build_query(['prediction_id' => $prediction->public_id, 'prediction_sport' => 'nba']))
        ->assertOk()->assertJsonCount(1, 'bets.data')->assertJsonCount(1, 'tracking.user_bets');
    $this->getJson('/api/v2/user-bets?'.http_build_query(['prediction_id' => $prediction->public_id, 'prediction_sport' => 'cfb']))
        ->assertNotFound();
});

it('preserves sportsbook spread signs through the dashboard card convention', function (float $homeLine, float $probability) {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    vueCanonicalFixture('cfb', $homeLine, $probability);
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    $prediction = $response->inertiaProps('sports.0.predictions.0');

    $api = $this->getJson('/api/v2/sports/cfb/predictions')->assertSuccessful()->json('data.0');
    expect((float) -$prediction['predicted_spread'])->toBe($homeLine)
        ->and((float) $api['model_bet_context']['model_home_spread'])->toBe($homeLine)
        ->and($prediction['win_probability'])->toBe($probability);
})->with([
    'Pitt home favorite' => [-11.7, 0.875],
    'West Virginia home underdog' => [3.1, 0.374],
    'pick em' => [0.0, 0.5],
]);

it('keeps kickoff and publication tie order without SQL sorting full dashboard prediction rows', function (?int $limit) {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $predictions = collect();
    foreach (['08:00:00', '08:05:00', '08:05:00', '08:10:00'] as $time) {
        Carbon::setTestNow('2026-10-10 '.$time);
        $predictions->push(vueCanonicalFixture('cfb', metadata: ['diagnostic_payload' => str_repeat('stored-input-', 5000)]));
    }
    Carbon::setTestNow('2026-10-10 12:00:00');
    foreach ($predictions as $index => $prediction) {
        $kickoff = now()->setTime($index === 3 ? 13 : 14, 0)->utc();
        $prediction->sportEvent->update(['starts_at' => $kickoff]);
        $prediction->sportEvent->cfbGame->update(['game_date' => $kickoff->toDateString(), 'game_time' => $kickoff->toTimeString()]);
    }
    if ($limit !== null) {
        config()->set('subscriptions.enforce_tiers', true);
        SubscriptionTier::query()->create([
            'name' => 'Free', 'slug' => 'free', 'features' => ['predictions_per_day' => $limit],
            'is_active' => true, 'is_default' => true, 'sort_order' => 0,
        ]);
    }
    $expected = array_slice([3, 2, 1, 0], 0, $limit);
    $hydrated = [];
    CanonicalPrediction::retrieved(function (CanonicalPrediction $prediction) use (&$hydrated): void {
        if (array_key_exists('output_metadata', $prediction->getAttributes())) {
            $hydrated[] = $prediction->public_id;
        }
    });
    DB::enableQueryLog();
    try {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('sports', 1)->has('sports.0.predictions', count($expected))
            ->where('sports.0.predictions', fn ($rows) => collect($rows)->pluck('id')->all() === collect($expected)->map(fn ($index) => $predictions[$index]->public_id)->all())
            ->where('stats.total_predictions_today', count($expected)));
        expect($hydrated)->toEqualCanonicalizing(collect($expected)->map(fn ($index) => $predictions[$index]->public_id)->all());
        $slateQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/select ["`]predictions["`]\\.\\*/i', $sql) === 1 && str_contains($sql, 'join'));
        expect($slateQueries)->toHaveCount(1)
            ->and(strtolower($slateQueries->sole()))->not->toContain('order by');
    } finally {
        DB::disableQueryLog();
    }
})->with([null, 2]);

it('paginates canonical forecasts with ordered IDs before hydrating large JSON rows', function (string $sport) {
    config()->set("prediction_lifecycle.canonical_reads.{$sport}", true);
    $rows = collect();
    foreach (['08:00:00', '08:05:00', '08:05:00', '08:10:00'] as $time) {
        Carbon::setTestNow('2026-10-10 '.$time);
        $rows->push(vueCanonicalFixture($sport, metadata: ['large_document' => str_repeat('frozen-evidence-', 5000)]));
    }
    Carbon::setTestNow('2026-10-10 12:00:00');
    $outside = vueCanonicalFixture($sport);
    $outside->sportEvent->{$sport.'Game'}->update(['game_date' => '2026-10-11']);
    $user = User::factory()->create();
    DB::enableQueryLog();
    try {
        foreach ([1 => [3, 2], 2 => [1, 0], 3 => []] as $page => $indices) {
            $response = $this->actingAs($user)->getJson('/api/v2/sports/'.$sport.'/predictions?season=2026&from_date=2026-10-10&to_date=2026-10-10&per_page=2&page='.$page)
                ->assertSuccessful()->assertJsonPath('meta.pagination.current_page', $page)
                ->assertJsonPath('meta.pagination.per_page', 2)->assertJsonPath('meta.pagination.total', 4)->assertJsonPath('meta.pagination.last_page', 2);
            expect(collect($response->json('data'))->pluck('id')->all())
                ->toBe(collect($indices)->map(fn ($index) => $rows[$index]->public_id)->all());
            foreach ($response->json('data') as $prediction) {
                expect($prediction['predicted_spread'])->toBe(4.5)
                    ->and($prediction['win_probability'])->toBe(0.65)
                    ->and($prediction['game']['home_team']['abbreviation'])->not->toBeNull()
                    ->and($prediction['audit_context']['input_snapshot_id'])->not->toBeNull();
            }
        }
        $queries = collect(DB::getQueryLog())->pluck('query');
        $hydration = $queries->filter(fn ($sql) => preg_match('/select ["`]predictions["`]\.\*/i', $sql) === 1 && str_contains($sql, 'game_date'));
        expect($hydration)->toHaveCount(2);
        foreach ($hydration as $sql) {
            expect(strtolower($sql))->not->toContain('order by')
                ->and(strtolower($sql))->toContain(' in ');
        }
        $orderedIds = $queries->filter(fn ($sql) => preg_match('/select ["`]predictions["`]\.["`]id["`]/i', $sql) === 1 && str_contains(strtolower($sql), 'order by'));
        expect($orderedIds)->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
})->with(['cfb', 'nfl', 'nba', 'cbb', 'wcbb', 'wnba', 'mlb']);

it('decodes frozen inputs once per API resource and releases the document after serialization', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $prediction = vueCanonicalFixture('cfb');
    $context = app(SportContextResolver::class)->resolve('cfb');
    $prediction = app(CanonicalSportPredictionQuery::class)->find($context, $prediction->public_id);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    $tracked = new class extends EventInputSnapshot
    {
        public int $inputDecodes = 0;

        public function fromJson($value, $asObject = false)
        {
            if ($value !== null && $value === $this->getRawOriginal('inputs')) {
                $this->inputDecodes++;
            }

            return parent::fromJson($value, $asObject);
        }
    };
    $tracked->setRawAttributes($snapshot->getAttributes(), true)->setRelations($snapshot->getRelations());
    $prediction->calculationRun->setRelation('inputSnapshot', $tracked);
    $resource = app(SportPredictionReader::class)->resource($context, $prediction);
    expect($tracked->inputDecodes)->toBe(0);
    $first = $resource->resolve();
    expect($tracked->inputDecodes)->toBe(1)
        ->and($first['id'])->toBe($prediction->public_id)
        ->and($first['predicted_spread'])->toBe(4.5);
    foreach ([$prediction, $prediction->calculationRun, $tracked] as $model) {
        $class = $model instanceof EventInputSnapshot ? EventInputSnapshot::class : get_class($model);
        expect((new ReflectionProperty($class, 'decodedJsonArrays'))->getValue($model))->toBeEmpty()
            ->and($model->isDirty())->toBeFalse();
    }
    expect($resource->resolve())->toBe($first)
        ->and($tracked->inputDecodes)->toBe(2);
});

it('hydrates dashboard forecast graphs in bounded unsorted batches without losing slate order', function () {
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $ids = [];
    for ($i = 0; $i < 13; $i++) {
        $prediction = vueCanonicalFixture('nba', $i + 0.5);
        $ids[] = $prediction->public_id;
    }
    DB::enableQueryLog();
    try {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('sports.0.predictions', 13)
            ->where('stats.total_predictions_today', 13));
        $queries = collect(DB::getQueryLog())->pluck('query');
        $fullRows = $queries->filter(fn ($sql) => str_contains($sql, 'select "predictions".*') && str_contains($sql, 'join'));
        expect($fullRows)->toHaveCount(3);
        foreach ($fullRows as $sql) {
            expect($sql)->not->toContain('order by');
        }
    } finally {
        DB::disableQueryLog();
    }
});

it('opts CFB boards into compact replacement and live responses without changing the full API', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $prediction = vueCanonicalFixture('cfb');
    $game = $prediction->sportEvent->cfbGame;
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'home_score' => 7, 'away_score' => 0]);
    $this->actingAs(User::factory()->create());
    $url = '/api/v2/sports/cfb/predictions?season=2026';
    $full = $this->getJson($url)->assertSuccessful()->assertJsonMissingPath('meta.board')->json('data.0');
    $board = $this->getJson($url.'&view=board')->assertSuccessful()->assertJsonPath('meta.board.mode', 'replace');
    expect($board->json('data.0.projection'))->toBe($full['projection'])
        ->and($board->json('data.0.weather_forecast'))->toBe($full['weather_forecast']);
    $version = $board->json('meta.board.version');
    $game->update(['home_score' => 0, 'away_score' => 14, 'period' => 2, 'game_clock' => '03:00']);
    $live = $this->getJson($url.'&view=live&board_version='.$version)->assertSuccessful()
        ->assertJsonPath('meta.board.mode', 'live')->assertJsonMissingPath('data.0.projection')
        ->assertJsonMissingPath('data.0.audit_context')->assertJsonPath('data.0.game.home_score', 0)
        ->assertJsonPath('data.0.game.away_score', 14)->assertJsonPath('data.0.game.clock', '03:00');
    expect($live->json('meta.pagination'))->toBe($board->json('meta.pagination'));
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->getJson($url.'&view=live&board_version='.$version)->assertSuccessful()
        ->assertJsonPath('meta.board.mode', 'replace')->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.total', 0);
});

it('validates board delivery selectors without weakening prediction access', function () {
    $url = '/api/v2/sports/cfb/predictions';
    $this->getJson($url.'?view=board')->assertUnauthorized();
    $this->getJson($url.'?view=startup')->assertUnauthorized();
    $this->actingAs(User::factory()->create());
    $this->getJson($url.'?view=invalid')->assertUnprocessable();
    $this->getJson($url.'?view=live&board_version=bad')->assertUnprocessable();
    $this->getJson('/api/v2/sports/nfl/predictions?view=board')->assertUnprocessable();
    $this->getJson('/api/v2/sports/nfl/predictions?view=startup')->assertUnprocessable();
    $this->getJson($url.'?view=startup&season=2025&week=0&from_date=2026-01-01')
        ->assertUnprocessable()->assertInvalid(['season', 'week', 'from_date']);
});

it('returns the same CFB startup week as the prediction lookup without loading forecasts', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    Carbon::setTestNow('2027-01-16 12:00:00 UTC');
    $slates = [
        ['2027-01-14', 2026, 3, 4],
        ['2027-01-16', 2026, 3, 5],
        ['2027-01-23', 2026, 3, 6],
        ['2027-09-04', 2027, 2, 0],
    ];
    foreach ($slates as [$date, $season, $type, $week]) {
        $prediction = vueCanonicalFixture('cfb');
        $prediction->sportEvent->cfbGame->update([
            'game_date' => $date, 'season' => $season, 'season_type' => $type, 'week' => $week,
        ]);
    }
    $this->actingAs(User::factory()->create());
    $url = '/api/v2/sports/cfb/predictions';
    $this->getJson($url.'/available-seasons')->assertSuccessful()
        ->assertJsonPath('data', [2027, 2026])->assertJsonMissingPath('meta.board_period');

    DB::enableQueryLog();
    try {
        $this->getJson($url.'/available-seasons?view=board')->assertSuccessful()
            ->assertJsonPath('data', [2027, 2026])
            ->assertJsonPath('meta.board_period', ['season' => 2026, 'season_type' => '3', 'week' => 5]);
        foreach (DB::getQueryLog() as $query) {
            expect($query['query'])->not->toMatch('/select\s+(?:["`]?predictions["`]?\.)?\*\s+from\s+["`]?predictions\b/i');
        }
    } finally {
        DB::disableQueryLog();
    }

    $this->getJson($url.'?season=2026&from_date=2027-01-16&to_date=2027-01-16&per_page=1')
        ->assertSuccessful()->assertJsonPath('data.0.game.week', 5);

    Carbon::setTestNow('2027-01-17 12:00:00 UTC');
    $this->getJson($url.'/available-seasons?view=board')->assertSuccessful()
        ->assertJsonPath('meta.board_period.week', 5);

    Carbon::setTestNow('2027-08-01 12:00:00 UTC');
    $this->getJson($url.'/available-seasons?view=board')->assertSuccessful()
        ->assertJsonPath('meta.board_period', ['season' => 2027, 'season_type' => '2', 'week' => 0]);
});

it('keeps CFB board startup metadata behind the prediction access boundary', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $url = '/api/v2/sports/cfb/predictions/available-seasons?view=board';
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson($url)->assertSuccessful()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.board_period', ['season' => null, 'season_type' => null, 'week' => null]);
    $this->getJson('/api/v2/sports/nfl/predictions/available-seasons?view=board')
        ->assertSuccessful()->assertJsonMissingPath('meta.board_period');
});

it('serves the selected CFB period and compact page together with a usable live token', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $prediction = vueCanonicalFixture('cfb');
    $game = $prediction->sportEvent->cfbGame;
    $game->update(['season_type' => 2, 'week' => 0, 'status' => 'STATUS_IN_PROGRESS']);
    $other = vueCanonicalFixture('cfb');
    $other->sportEvent->cfbGame->update(['game_date' => now()->addWeek()->toDateString(), 'season_type' => 2, 'week' => 1]);
    $this->actingAs(User::factory()->create());

    $url = '/api/v2/sports/cfb/predictions';
    $filters = '&season=2026&season_type=2&week=0';
    $startup = $this->getJson($url.'?view=startup&page=1')->assertSuccessful()
        ->assertJsonPath('meta.board_startup.seasons', [2026])
        ->assertJsonPath('meta.board_startup.period', ['season' => 2026, 'season_type' => '2', 'week' => 0])
        ->assertJsonPath('meta.filters.week', 0)
        ->assertJsonPath('meta.board.mode', 'replace')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prediction->public_id)
        ->assertJsonMissingPath('data.0.audit_context');

    $explicit = $this->getJson($url.'?view=board'.$filters)->assertSuccessful()
        ->assertJsonMissingPath('meta.board_startup');
    expect($startup->json('data'))->toBe($explicit->json('data'))
        ->and($startup->json('meta.pagination'))->toBe($explicit->json('meta.pagination'));

    $game->update(['home_score' => 7]);
    $this->getJson($url.'?view=live'.$filters.'&board_version='.$startup->json('meta.board.version'))
        ->assertSuccessful()->assertJsonPath('meta.board.mode', 'live')
        ->assertJsonPath('data.0.game.home_score', 7)->assertJsonMissingPath('data.0.projection');
});

it('returns a complete CFB startup response when there are no eligible forecasts', function () {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $this->actingAs(User::factory()->create())->getJson('/api/v2/sports/cfb/predictions?view=startup')
        ->assertSuccessful()
        ->assertJsonPath('meta.board_startup.seasons', [])
        ->assertJsonPath('meta.board_startup.period', ['season' => null, 'season_type' => null, 'week' => null])
        ->assertJsonPath('meta.pagination.total', 0)
        ->assertJsonPath('meta.board.mode', 'replace')
        ->assertJsonCount(0, 'data');
});

it('keeps CFB live and weather fields identical between compact dashboard and full scoreboard rows', function (int $snapshotAge) {
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    $prediction = vueCanonicalFixture('cfb');
    $game = $prediction->sportEvent->cfbGame;
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'home_score' => 0, 'away_score' => 14, 'period' => 2, 'game_clock' => '05:00']);
    $game->weather()->create([
        'provider' => 'fixture', 'observed_at' => now(), 'temperature_f' => 52,
        'wind_speed_mph' => 8, 'precipitation_inches' => 0,
    ]);
    LivePredictionSnapshot::create([
        'game_id' => $game->id, 'state_hash' => hash('sha256', 'dashboard-live'), 'source' => 'scoreboard',
        'status' => 'live', 'pregame' => [], 'state' => [], 'markets' => [], 'props' => [],
        'projection' => ['spread' => -7, 'total' => 0, 'home_win_probability' => 0.25, 'seconds_remaining' => 2000],
        'observed_at' => now()->subMinutes($snapshotAge),
    ]);

    $this->actingAs(User::factory()->create());
    $dashboard = $this->get(route('dashboard'))->assertSuccessful()->inertiaProps('sports.0.predictions.0');
    $scoreboard = $this->getJson('/api/v2/live-scoreboard')->assertSuccessful()->json('data.games.0');

    expect($dashboard)->toEqual(array_intersect_key($scoreboard, $dashboard))
        ->and($dashboard['home_score'])->toBe(0)
        ->and($dashboard['weather_forecast']['source'])->toBe('latest_forecast')
        ->and($dashboard['live_status'])->toBe($snapshotAge === 0 ? 'live' : 'stale')
        ->and($dashboard['live_predicted_total'])->toBe($snapshotAge === 0 ? 0 : null);
})->with([0, 4]);
