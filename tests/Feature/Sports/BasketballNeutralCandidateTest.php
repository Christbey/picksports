<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\EventInputSnapshot;
use App\Models\ModelArtifact;
use App\Models\SportEvent;
use App\Services\Predictions\Basketball\BasketballPolicyCalculator;
use App\Services\Predictions\Basketball\NeutralAwareBasketballInputSnapshotBuilder;
use App\Services\Predictions\CalculationReleaseManager;
use App\Services\Predictions\CalculationReleaseSelector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    Http::preventStrayRequests();
});

function basketballNeutralRegistrar(string $sport): object
{
    $prefix = ['nba' => 'Nba', 'cbb' => 'Cbb', 'wnba' => 'Wnba', 'wcbb' => 'Wcbb'][$sport];

    return app('App\\Services\\'.strtoupper($sport).'\\Predictions\\'.$prefix.'CalculationReleaseRegistrar');
}

function basketballNeutralCandidateGame(string $sport, ?bool $observed): Model
{
    $namespace = strtoupper($sport);
    $team = 'App\\Models\\'.$namespace.'\\Team';
    $game = 'App\\Models\\'.$namespace.'\\Game';
    $start = now()->addDay()->utc();
    $event = SportEvent::factory()->create(['sport' => $sport, 'neutral_site' => $observed,
        'starts_at' => $start, 'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED']);

    return $game::factory()->create(['sport_event_id' => $event->id, 'neutral_site' => $observed,
        'home_team_id' => $team::factory()->create(['elo_rating' => 1500])->id,
        'away_team_id' => $team::factory()->create(['elo_rating' => 1500])->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s')]);
}

it('registers an opt-in draft without changing active release frozen snapshot or output', function (string $sport) {
    $game = basketballNeutralCandidateGame($sport, true);
    $registrar = basketballNeutralRegistrar($sport);
    $old = $registrar->register(effectiveAt: now()->subMinute()->toImmutable());
    $action = app('App\\Actions\\'.strtoupper($sport).'\\GenerateCanonicalPrediction');
    $prediction = $action->execute($game, publish: false);
    $oldAttributes = $old->fresh()->getAttributes();
    $snapshot = $prediction->calculationRun->inputSnapshot;
    $snapshotBytes = $snapshot->getRawOriginal('inputs');
    $output = $prediction->fresh()->getAttributes();
    $snapshotCount = EventInputSnapshot::count();
    $artifactCount = ModelArtifact::count();

    $this->artisan($sport.':register-calculation-release', ['--neutral-aware-draft' => true])
        ->expectsOutputToContain('draft')->assertSuccessful();
    $draft = CalculationRelease::where('sport', $sport)->where('semantic_version', '1.1.0')->sole();
    expect($draft->status)->toBe('draft')->and($draft->approved_at)->toBeNull()
        ->and($draft->effective_at)->toBeNull()->and($draft->retired_at)->toBeNull()
        ->and(data_get($draft->configuration, 'elo.respect_neutral_site'))->toBeTrue()
        ->and($draft->input_schema_version)->toBe($sport.'-pregame-neutral-v2')
        ->and(app(CalculationReleaseSelector::class)->select($game->sportEvent, 'pregame')->id)->toBe($old->id)
        ->and($old->fresh()->getAttributes())->toBe($oldAttributes)
        ->and($snapshot->fresh()->getRawOriginal('inputs'))->toBe($snapshotBytes)
        ->and($prediction->fresh()->getAttributes())->toBe($output)
        ->and(EventInputSnapshot::count())->toBe($snapshotCount)
        ->and(ModelArtifact::count())->toBe($artifactCount);
})->with(['nba', 'cbb', 'wnba']);

it('keeps candidate input observations nullable with explicit capture fallback', function (string $sport, ?bool $observed) {
    $game = basketballNeutralCandidateGame($sport, $observed);
    $release = basketballNeutralRegistrar($sport)->registerNeutralAwareDraft();
    $snapshot = app(NeutralAwareBasketballInputSnapshotBuilder::class)->build($game->sportEvent, CalculationReleaseData::fromModel($release));
    expect($snapshot->schemaVersion)->toBe($sport.'-pregame-neutral-v2')
        ->and(data_get($snapshot->inputs, 'event.neutral_site'))->toBe($observed)
        ->and(data_get($snapshot->metadata, 'neutral_context.unknown_fallback_applied'))->toBe($observed === null)
        ->and(data_get($snapshot->metadata, 'neutral_context.historical_availability_verified'))->toBeFalse()
        ->and($release->fresh()->status)->toBe('draft');
    $prefix = ['nba' => 'Nba', 'cbb' => 'Cbb', 'wnba' => 'Wnba', 'wcbb' => 'Wcbb'][$sport];
    $calculator = app('App\\Services\\'.strtoupper($sport).'\\Predictions\\'.$prefix.'Calculator');
    $output = (new BasketballPolicyCalculator($calculator, $sport))->calculate($snapshot, CalculationReleaseData::fromModel($release));
    $expectedMargin = $observed === true ? 0.0 : round((float) data_get($release->configuration, 'elo.home_court_advantage') / (float) data_get($release->configuration, 'elo.points_per_spread_point'), 4);
    expect($output->diagnostics['elo_home_margin'])->toBe($expectedMargin)
        ->and($output->diagnostics['neutral_policy']['observed_neutral_site'])->toBe($observed)
        ->and($output->diagnostics['neutral_policy']['snapshot_schema'])->toBe($sport.'-pregame-neutral-v2');
})->with(['nba', 'cbb', 'wnba'])->with([true, false, null]);

it('refuses candidate activation or conflicting explicit version options without registration', function (string $sport, array $options) {
    $this->artisan($sport.':register-calculation-release', ['--neutral-aware-draft' => true, ...$options])->assertExitCode(2);
    expect(CalculationRelease::count())->toBe(0);
})->with(['nba', 'cbb', 'wnba'])->with([
    [['--release-version' => '1.0.0']], [['--effective-at' => '2026-11-26T00:00:00Z']],
]);

it('refuses an existing non-draft candidate without downgrading immutable state', function (string $sport, string $status) {
    $registrar = basketballNeutralRegistrar($sport);
    $release = $registrar->registerNeutralAwareDraft();
    $manager = app(CalculationReleaseManager::class);
    $release = $manager->approve($release, 'synthetic-reviewer', 'Synthetic collision control.', now()->subMinute()->toImmutable());
    if ($status === 'retired') {
        $release = $manager->retire($release);
    }
    $before = $release->fresh()->getAttributes();
    expect(fn () => $registrar->registerNeutralAwareDraft())->toThrow(PredictionLifecycleException::class);
    expect($release->fresh()->getAttributes())->toBe($before)->and(CalculationRelease::count())->toBe(1);
})->with(['nba', 'cbb', 'wnba'])->with(['approved', 'retired']);

it('refuses conflicting frozen parameters in an existing candidate draft', function (string $sport) {
    $registrar = basketballNeutralRegistrar($sport);
    $release = $registrar->registerNeutralAwareDraft();
    $before = $release->fresh()->getAttributes();
    config([$sport.'.elo.home_court_advantage' => 123456]);
    expect(fn () => $registrar->registerNeutralAwareDraft())->toThrow(PredictionLifecycleException::class);
    expect($release->fresh()->getAttributes())->toBe($before)->and(CalculationRelease::count())->toBe(1);
})->with(['nba', 'cbb', 'wnba']);

it('uses only the compiled candidate adapter after an explicit synthetic approval', function (string $sport) {
    $game = basketballNeutralCandidateGame($sport, true);
    $release = basketballNeutralRegistrar($sport)->registerNeutralAwareDraft();
    app(CalculationReleaseManager::class)->approve($release, 'synthetic-test-reviewer', 'Explicit test-only policy selection.', now()->subMinute()->toImmutable());
    $action = app('App\\Actions\\'.strtoupper($sport).'\\GenerateCanonicalPrediction');
    $prediction = $action->execute($game, publish: false);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    expect($snapshot->schema_version)->toBe($sport.'-pregame-neutral-v2')
        ->and(data_get($snapshot->inputs, 'event.neutral_site'))->toBeTrue()
        ->and(data_get($snapshot->metadata, 'neutral_context.historical_availability_verified'))->toBeFalse()
        ->and(ModelArtifact::count())->toBe(0);
})->with(['nba', 'cbb', 'wnba']);

it('preserves exact legacy calculator payload and diagnostics through the adapter', function (string $sport) {
    $game = basketballNeutralCandidateGame($sport, true);
    $release = CalculationReleaseData::fromModel(basketballNeutralRegistrar($sport)->register());
    $prefix = ['nba' => 'Nba', 'cbb' => 'Cbb', 'wnba' => 'Wnba', 'wcbb' => 'Wcbb'][$sport];
    $namespace = 'App\\Services\\'.strtoupper($sport).'\\Predictions\\';
    $builder = app($namespace.$prefix.'InputSnapshotBuilder');
    $calculator = app($namespace.$prefix.'Calculator');
    $snapshot = $builder->build($game->sportEvent, $release);
    $direct = $calculator->calculate($snapshot, $release);
    $adapted = (new BasketballPolicyCalculator($calculator, $sport))->calculate($snapshot, $release);
    expect(json_encode($adapted->hashablePayload()))->toBe(json_encode($direct->hashablePayload()))
        ->and($adapted->diagnostics)->toBe($direct->diagnostics);
})->with(['nba', 'cbb', 'wnba']);

it('refuses unsupported candidate calculator combinations before invoking math', function (string $sport, string $change) {
    $game = basketballNeutralCandidateGame($sport, true);
    $release = CalculationReleaseData::fromModel(basketballNeutralRegistrar($sport)->registerNeutralAwareDraft());
    $snapshot = app(NeutralAwareBasketballInputSnapshotBuilder::class)->build($game->sportEvent, $release);
    $releaseFields = get_object_vars($release);
    $snapshotFields = get_object_vars($snapshot);
    match ($change) {
        'version' => $releaseFields['semanticVersion'] = '1.2.0',
        'policy' => $releaseFields['configuration']['elo']['respect_neutral_site'] = false,
        'schema' => $releaseFields['inputSchemaVersion'] = $snapshotFields['schemaVersion'] = 'unknown-schema',
        'phase' => $releaseFields['phase'] = 'live',
        'sport' => $releaseFields['sport'] = 'mlb',
        'malformed' => $snapshotFields['inputs']['event']['neutral_site'] = 'false',
    };
    $legacy = Mockery::mock(SportCalculator::class);
    $legacy->shouldNotReceive('calculate');
    $adapter = new BasketballPolicyCalculator($legacy, $sport);
    expect(fn () => $adapter->calculate(new EventInputSnapshotData(...$snapshotFields), new CalculationReleaseData(...$releaseFields)))
        ->toThrow(PredictionLifecycleException::class);
})->with(['nba', 'cbb', 'wnba'])->with(['version', 'policy', 'schema', 'phase', 'sport', 'malformed']);

it('refuses a contradictory draft lifecycle timestamp without rewriting it', function (string $sport, string $timestamp) {
    $registrar = basketballNeutralRegistrar($sport);
    $release = $registrar->registerNeutralAwareDraft();
    $release->newQuery()->whereKey($release->id)->update([$timestamp => now()]);
    $before = $release->fresh()->getAttributes();
    expect(fn () => $registrar->registerNeutralAwareDraft())->toThrow(PredictionLifecycleException::class);
    expect($release->fresh()->getAttributes())->toBe($before)
        ->and(CalculationRelease::count())->toBe(1);
})->with(['nba', 'cbb', 'wnba'])->with(['effective_at', 'approved_at', 'retired_at', 'invalidated_at']);

it('keeps the published full-model identity unchanged when staging a neutral draft', function (string $sport) {
    $game = basketballNeutralCandidateGame($sport, true);
    $registrar = basketballNeutralRegistrar($sport);
    $full = $registrar->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $before = $full->fresh()->getAttributes();
    $draft = $registrar->registerNeutralAwareDraft();
    expect($draft->id)->not->toBe($full->id)
        ->and($draft->calculator_name)->toBe($full->calculator_name.'-neutral-candidate')
        ->and($draft->status)->toBe('draft')->and($draft->effective_at)->toBeNull()
        ->and($full->fresh()->getAttributes())->toBe($before)
        ->and(app(CalculationReleaseSelector::class)->select($game->sportEvent, 'pregame')->id)->toBe($full->id);
})->with(['nba', 'cbb', 'wnba', 'wcbb']);
