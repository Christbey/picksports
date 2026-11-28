<?php

use App\Actions\NFL\CalculateElo;
use App\Exceptions\EloAdmissionRefused;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloWriterGate;
use App\Models\SportEvent;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloInputAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\NFL\NflGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function importedClockEloFixture(): array
{
    Http::preventStrayRequests();
    test()->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    config(['elo_integrity.enabled' => true]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nfl' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'protocol_version' => 1,
        'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
        'drain_reference' => 'synthetic-import-clock-drain', 'drain_verified_at' => now()->subMinute()]);
    $teams = Team::factory()->count(2)->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nfl', 2026, 'team', $teams->modelKeys());
    $admission->apply('nfl', 2026, 'team', $teams->modelKeys(), $report['fingerprint'], 'synthetic-import-clock-baseline');
    config(['elo_integrity.write_target_fingerprints.nfl' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'active']);

    return $teams->all();
}

function importedClockPayload(string $id, ?string $commence = null): array
{
    return ['event_id' => 'nflverse:'.$id, 'commence_time' => $commence,
        'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']];
}

it('refuses a metadata-proven missing imported clock before a managed final writes Elo', function () {
    [$home, $away] = importedClockEloFixture();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'game_date' => '2026-10-01', 'game_time' => '00:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 10,
        'nflverse_game_id' => 'synthetic-missing-final', 'odds_data' => importedClockPayload('synthetic-missing-final')]);
    expect(NflGameStart::for($game))->toBeNull();
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(EloAdmissionRefused::class);
    expect(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0)
        ->and((float) $home->fresh()->elo_rating)->toBe(1500.0);
});

it('refuses a missing imported target clock despite an old nonnull canonical event', function () {
    [$home, $away] = importedClockEloFixture();
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026, 'season_type' => '2',
        'status' => 'STATUS_SCHEDULED', 'starts_at' => now()->addDay()]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'game_date' => '2026-11-27', 'game_time' => '00:00:00',
        'status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null,
        'nflverse_game_id' => 'synthetic-missing-target', 'odds_data' => importedClockPayload('synthetic-missing-target')]);
    expect(fn () => app(EloInputAdmission::class)->teams('nfl', $game))->toThrow(EloAdmissionRefused::class);
    expect(EventInputSnapshot::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
});

it('refuses oversized candidate metadata before selecting its odds body', function () {
    [$home, $away] = importedClockEloFixture();
    $payload = [...importedClockPayload('synthetic-large-clock'), 'padding' => str_repeat('x', 20000)];
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'game_date' => '2026-10-01', 'game_time' => '00:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 10,
        'nflverse_game_id' => 'synthetic-large-clock', 'odds_data' => $payload]);
    $bodyReads = 0;
    DB::listen(function ($query) use (&$bodyReads) {
        $sql = strtolower(str_replace(['`', '"'], '', $query->sql));
        if (str_contains($sql, 'select odds_data from nfl_games') || str_contains($sql, 'select * from nfl_games')) {
            $bodyReads++;
        }
    });
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(EloAdmissionRefused::class);
    expect($bodyReads)->toBe(0)->and(SportEloApplication::count())->toBe(0);
});

it('preserves a genuinely explicit midnight observation rather than banning all zero clocks', function () {
    [$home, $away] = importedClockEloFixture();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'game_date' => '2026-10-01', 'game_time' => '00:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 10,
        'nflverse_game_id' => 'synthetic-observed-midnight',
        'odds_data' => importedClockPayload('synthetic-observed-midnight', '2026-10-01T00:00:00Z')]);
    expect(NflGameStart::for($game))->not->toBeNull();
    (new CalculateElo)->execute($game);
    expect(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2);
});

it('classifies only precise missing metadata with all-zero fractional clock representations', function (string $clock, ?string $commence, bool $missing) {
    expect(NflGameStart::knownMissingImportedClockObservation('synthetic-fractional', $clock, importedClockPayload('synthetic-fractional', $commence)))->toBe($missing);
})->with([
    ['00:00:00.000000', null, true],
    ['00:00:00.0', null, true],
    ['00:00:00.000001', null, false],
    ['00:00:00.000000', '2026-10-01T00:00:00Z', false],
    ['00:00:01', null, false],
]);
