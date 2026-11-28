<?php

use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\SportEloWriterGate;
use App\Models\SportEvent;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function managedSnapshotFixture(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    Http::preventStrayRequests();
    Queue::fake();
    config(['elo_integrity.enabled' => false]);
    $teams = Team::factory()->count(2)->create(['elo_rating' => 1500]);
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2026, 'season_type' => '2',
        'starts_at' => CarbonImmutable::parse('2026-11-27 18:00:00 UTC')->setTimezone((string) config('app.timezone')),
        'status' => 'STATUS_SCHEDULED', 'neutral_site' => false]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $teams[0]->id,
        'away_team_id' => $teams[1]->id, 'season' => 2026, 'season_type' => 2, 'week' => 1,
        'game_date' => '2026-11-27', 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED',
        'home_score' => null, 'away_score' => null, 'neutral_site' => false]);
    app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());

    return [$game, $teams];
}

function admitManagedSnapshotTeams($teams): void
{
    config(['elo_integrity.enabled' => true]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nba' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('nba', 'team');
    SportEloWriterGate::whereKey('nba')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-snapshot-identity-drain',
        'drain_verified_at' => now()->subMinute()]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nba', 2026, 'team', $teams->modelKeys());
    $admission->apply('nba', 2026, 'team', $teams->modelKeys(), $report['fingerprint'], 'synthetic-snapshot-baseline');
    config(['elo_integrity.write_target_fingerprints.nba' => app(EloWriteTargetIdentity::class)->describe($teams[0]->getConnection())['fingerprint']]);
    SportEloWriterGate::whereKey('nba')->update(['mode' => 'active']);
}

it('does not reuse an unmanaged snapshot merely because managed scalar inputs match', function () {
    [$game, $teams] = managedSnapshotFixture();
    $legacy = app(GenerateCanonicalPrediction::class)->execute($game, false);
    $oldSnapshot = $legacy->calculationRun->inputSnapshot->fresh();
    $before = $oldSnapshot->getAttributes();
    expect(data_get($oldSnapshot->metadata, 'elo_input_admission'))->toBeNull();
    admitManagedSnapshotTeams($teams);
    $managed = app(GenerateCanonicalPrediction::class)->execute($game->fresh(), false);
    $snapshot = $managed->calculationRun->inputSnapshot->fresh();
    expect($snapshot->id)->not->toBe($oldSnapshot->id)
        ->and(data_get($snapshot->metadata, 'elo_input_admission'))->toBeArray()
        ->and($oldSnapshot->fresh()->getAttributes())->toBe($before);
});

it('reuses the first qualified capture for equivalent managed evidence without hashing retry time', function () {
    [$game, $teams] = managedSnapshotFixture();
    admitManagedSnapshotTeams($teams);
    $first = app(GenerateCanonicalPrediction::class)->execute($game, false);
    $snapshot = $first->calculationRun->inputSnapshot->fresh();
    $before = $snapshot->getAttributes();
    test()->travel(5)->seconds();
    $again = app(GenerateCanonicalPrediction::class)->execute($game->fresh(), false);
    expect($again->id)->toBe($first->id)
        ->and($again->calculationRun->inputSnapshot->id)->toBe($snapshot->id)
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
});

it('reuses qualified evidence after storage reorders portable policy object keys', function () {
    [$game, $teams] = managedSnapshotFixture();
    admitManagedSnapshotTeams($teams);
    $first = app(GenerateCanonicalPrediction::class)->execute($game, false);
    $snapshot = $first->calculationRun->inputSnapshot->fresh();
    $metadata = $snapshot->metadata;
    $metadata['elo_sql_clock_policy'] = array_reverse($metadata['elo_sql_clock_policy'], true);
    // Emulate a JSON storage engine's object key normalization without changing any facts.
    $snapshot->getConnection()->table($snapshot->getTable())->where('id', $snapshot->id)
        ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    $before = $snapshot->fresh()->getAttributes();
    $again = app(GenerateCanonicalPrediction::class)->execute($game->fresh(), false);
    expect($again->id)->toBe($first->id)
        ->and($again->calculationRun->inputSnapshot->id)->toBe($snapshot->id)
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
});
