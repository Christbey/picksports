<?php

use App\Actions\NFL\CalculateTeamTrends;
use App\Actions\Trends\Collectors\AdvancedTrendCollector;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\SportContextResolver;
use App\Services\NFL\NflTeamEvidenceService;
use App\Services\Trends\TrendForecastSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
    Cache::flush();
});
afterEach(fn () => $this->travelBack());

function trendRetirementFixture(string $sport, string $variant = 'valid', int $count = 5): array
{
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $legacyClass = $context->models['prediction'];
    $home = $teamClass::factory()->create(['abbreviation' => 'HME']);
    $away = $teamClass::factory()->create(['abbreviation' => 'AWY']);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame',
        'effective_at' => '2026-09-01', 'approved_at' => '2026-09-01']);
    $games = collect();
    $natives = collect();
    for ($i = 0; $i < $count; $i++) {
        $start = CarbonImmutable::parse('2026-10-20T17:00:00Z')->subDays($i);
        $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => $start]);
        $game = $gameClass::factory()->create(['sport_event_id' => $event->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => '2',
            'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
            'status' => 'STATUS_FINAL', 'home_score' => 30, 'away_score' => 20]);
        $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
            'phase' => 'pregame', 'pregame_safety_status' => $variant === 'unverified' ? 'unverified' : 'verified',
            'captured_at' => $variant === 'late_input' ? $start->addMinute() : $start->subHours(2),
            'cutoff_at' => $variant === 'late_input' ? $start->addMinute() : $start->subHours(2),
            'latest_source_available_at' => $start->subHours(3),
            'source_timestamps' => ['ratings' => $start->subHours(3)->toIso8601String()],
            'inputs' => ['home' => ['elo' => 1600], 'away' => ['elo' => 1600]]]);
        $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
            'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded']);
        $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
            'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
            'generated_at' => $start->subHour(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
        foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -8.0, null], ['total', 'combined', 40.0, null]] as [$type, $side, $line, $probability]) {
            PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
                'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
        }
        $native->update(['publication_state' => 'published', 'published_at' => $variant === 'kickoff_publication' ? $start : $start->subMinutes(30)]);
        $eloFields = $sport === 'mlb' ? ['home_team_elo' => 1000, 'away_team_elo' => 1000] : ['home_elo' => 1000, 'away_elo' => 1000];
        $legacyClass::create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
            'predicted_spread' => $sport === 'nfl' ? -50 : 50, 'predicted_total' => 100,
            'win_probability' => 0.1, 'updated_at' => $start->subHour(), ...$eloFields]);
        $games->push($game);
        $natives->push($native->refresh());
    }

    return [$home, $away, $games, $natives];
}

it('uses verified canonical model margins totals and Elo across every sport without legacy reads', function (string $sport) {
    [$home, $away, $games] = trendRetirementFixture($sport);
    $calculator = app('App\\Actions\\'.strtoupper($sport).'\\CalculateTeamTrends');
    DB::enableQueryLog();
    try {
        $result = $calculator->execute($home, gameCount: 5, season: 2026, beforeDate: now()->utc()->toIso8601String());
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($result['sample_size'])->toBe(5)
        ->and(implode(' ', $result['trends']['situational']))->toContain('made them favorites')->not->toContain('made them underdogs')
        ->and(implode(' ', $result['trends']['totals']))->toContain('OVER the model total')
        ->and(implode(' ', $result['trends']['opponent_strength']))->toContain('pregame Elo 1550+')
        ->and($queries)->not->toContain('"'.$sport.'_predictions"');
    app(TrendForecastSource::class)->preload($games, $sport);
    $homeResult = (new AdvancedTrendCollector)->setContext($sport, $home, $games)->collect();
    $awayResult = (new AdvancedTrendCollector)->setContext($sport, $away, $games)->collect();
    expect(implode(' ', $homeResult))->toContain('big favorites', '5-0 (100%) against the model spread')
        ->and(implode(' ', $awayResult))->toContain('big underdogs', '0-5 (0%) against the model spread');
})->with(['nba', 'nfl', 'cbb', 'cfb', 'mlb', 'wcbb', 'wnba']);

it('withholds unsafe canonical trend context instead of substituting legacy inputs', function (string $variant) {
    [$home] = trendRetirementFixture('nfl', $variant);
    $result = app(CalculateTeamTrends::class)->execute($home, gameCount: 5, season: 2026);
    expect($result['sample_size'])->toBe(5)->and($result['trends'])->not->toHaveKeys(['advanced', 'opponent_strength'])
        ->and(implode(' ', $result['trends']['totals']))->not->toContain('model total');
})->with(['late_input', 'unverified', 'kickoff_publication']);

it('withholds withdrawn revisions while preserving observed NFL profile evidence', function () {
    [$home, , , $natives] = trendRetirementFixture('nfl');
    $service = app(NflTeamEvidenceService::class);
    $before = $service->build($home, 2026, now()->utc()->toIso8601String());
    expect($before['source'])->toBe('nfl_games+nfl_team_stats+eligible_canonical_predictions')
        ->and($before['windows']['recent_5']['trends'])->toHaveKey('advanced');
    foreach ($natives as $native) {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }
    $after = $service->build($home, 2026, now()->utc()->toIso8601String());
    expect($after['windows']['recent_5']['sample_size'])->toBe(5)
        ->and($after['windows']['recent_5']['evidence'])->toBe($before['windows']['recent_5']['evidence'])
        ->and($after['windows']['recent_5']['trends'])->not->toHaveKeys(['advanced', 'opponent_strength']);
});

it('preserves legacy conventions and keeps API cache entries separate after read cutover', function () {
    [$home] = trendRetirementFixture('nfl');
    Sanctum::actingAs(User::factory()->create());
    config(['prediction_lifecycle.canonical_reads.nfl' => false]);
    $url = '/api/v2/sports/nfl/teams/'.$home->id.'/trends?season=2026&games=season';
    $legacy = $this->getJson($url)->assertSuccessful()->json('data.trends');
    expect(implode(' ', $legacy['situational']))->toContain('made them underdogs');
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $native = $this->getJson($url)->assertSuccessful()->json('data.trends');
    expect(implode(' ', $native['situational']))->toContain('made them favorites')->not->toContain('made them underdogs');
});

it('does not expose a canonical forecast before its publication cutoff', function () {
    [, , $games, $natives] = trendRetirementFixture('nfl', count: 1);
    $source = app(TrendForecastSource::class);
    $source->preload($games, 'nfl', $natives->sole()->published_at->subSecond());
    expect($source->forGame($games->sole(), 'nfl'))->toBeNull();
});

it('loads native history across batch boundaries and refreshes reused collections after source cutover', function () {
    [, , $games] = trendRetirementFixture('nba', count: 26);
    $source = app(TrendForecastSource::class);
    $games->each(fn ($game) => $game->load('prediction'));
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $source->preload($games, 'nba');
    expect($source->forGame($games->last(), 'nba')->predictedSpread)->toBe(-50.0);
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $source->preload($games, 'nba');
    expect($games->filter(fn ($game) => $source->forGame($game, 'nba')?->predictedSpread === 8.0))->toHaveCount(26);
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $source->preload($games, 'nba');
    expect($source->forGame($games->first(), 'nba')->predictedSpread)->toBe(-50.0);
});
