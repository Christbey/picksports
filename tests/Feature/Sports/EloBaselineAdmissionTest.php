<?php

use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\NFL\EloRating;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function enableSyntheticCoordinatedEloAdmission(string $sport = 'nfl'): void
{
    // Explicit test-only capability; the real registry remains empty in this slice.
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return in_array($sport, SportEloWriterGate::SPORTS, true) && $protocol === 1;
        }
    });
    config(['elo_integrity.enabled' => true]);
    $definition = app(EloSportRegistry::class)->resolve($sport, 'team');
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'admitting', 'protocol_version' => 1,
        'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
        'drain_reference' => 'synthetic-idle-writer-fixture', 'drain_verified_at' => now()->subMinute()]);
}

function coherentLegacyEloBaseline(string $sport = 'nfl', int $season = 2026): array
{
    $namespace = 'App\\Models\\'.strtoupper($sport).'\\';
    $teamModel = $namespace.'Team';
    $gameModel = $namespace.'Game';
    $ratingModel = $namespace.'EloRating';
    $home = $teamModel::factory()->create(['elo_rating' => 1640]);
    $away = $teamModel::factory()->create(['elo_rating' => 1360]);
    $factory = $gameModel::factory();
    if ($sport === 'mlb') {
        $factory = $factory->withSyntheticWallStart('UTC');
    }
    $game = $factory->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'week' => 1, 'status' => 'STATUS_FINAL', 'game_date' => $season.'-10-01', 'game_time' => '12:00:00',
        'home_score' => 20, 'away_score' => 10]);
    $date = $sport === 'wcbb' ? 'game_date' : 'date';
    foreach ([[$home, 1640], [$away, 1360]] as [$team, $rating]) {
        $ratingModel::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => $season, 'week' => 1,
            $date => $season.'-10-01', 'elo_rating' => $rating, 'elo_change' => 1]);
    }

    return [$home, $away, $game];
}

it('keeps gates disabled and produces a bounded dry run without manufacturing history', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    $before = $team->fresh()->getAttributes();
    expect(SportEloWriterGate::query()->count())->toBe(5)
        ->and(SportEloWriterGate::query()->pluck('mode')->unique()->all())->toBe(['disabled']);
    $exit = Artisan::call('sports:admit-elo-baseline', ['sport' => 'nfl', '--season' => 2026, '--ids' => [$team->id]]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($report['items'][0]['rating'])->toBe('1500.0000')
        ->and($report['apply_available'])->toBeFalse()->and($team->fresh()->getAttributes())->toBe($before)
        ->and(SportEloSeasonInitialization::count())->toBe(0)->and(SportEloEntityHead::count())->toBe(0);
});

it('does not allow configuration or a gate row to bypass the real empty adapter registry', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    config(['elo_integrity.enabled' => true]);
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'not-a-coordinated-adapter', 'drain_verified_at' => now()->subMinute()]);
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture'))
        ->toThrow(RuntimeException::class, 'complete coordinated writer adapter');
    expect(SportEloSeasonInitialization::count())->toBe(0)->and(SportEloEntityHead::count())->toBe(0);
});

it('creates exact admission receipts and heads only in a synthetic coordinated mode and reuses unchanged admission', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    $before = $team->fresh()->getAttributes();
    $result = $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture');
    $receipt = SportEloSeasonInitialization::sole();
    $raw = $receipt->getAttributes();
    $head = SportEloEntityHead::sole()->getAttributes();
    expect($receipt->rating_after)->toBe('1500.0000')->and($receipt->regression_factor)->toBeNull()
        ->and($receipt->kind)->toBe('baseline_admission')->and($receipt->baseline_kind)->toBe('declared_default')
        ->and($receipt->evidence['historical_calculation_provenance'])->toBe('declared_default_now')
        ->and($result['legacy_projections_changed'])->toBeFalse();
    $again = $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'second-review');
    expect($again['receipt_ids'])->toBe($result['receipt_ids'])->and($receipt->fresh()->getAttributes())->toBe($raw)
        ->and(SportEloEntityHead::sole()->getAttributes())->toBe($head)->and($team->fresh()->getAttributes())->toBe($before)
        ->and(SportEloApplication::count())->toBe(0)->and(SportEloApplicationEffect::count())->toBe(0);
});

it('refuses an incoherent or inactive writer gate despite a synthetic coordinated adapter', function (array $change) {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    SportEloWriterGate::whereKey('nfl')->update($change);
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    expect($report['apply_available'])->toBeFalse();
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture'))
        ->toThrow(RuntimeException::class, 'writer mode or drain');
    expect(SportEloSeasonInitialization::count())->toBe(0);
})->with([[['mode' => 'disabled']], [['mode' => 'active']], [['protocol_version' => 2]],
    [['drain_reference' => null]], [['drain_verified_at' => null]], [['configuration_hash' => str_repeat('f', 64)]], [['calculation_version' => 'other']]]);

it('refuses drift from a dry run without changing any legacy rows or creating receipts', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    $team->update(['elo_rating' => 1600]);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture'))->toThrow(RuntimeException::class);
    expect(SportEloSeasonInitialization::count())->toBe(0)->and((int) $team->fresh()->elo_rating)->toBe(1600);
});

it('rolls back the entire admission batch if a later head write fails', function () {
    $teams = Team::factory()->count(2)->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $ids = $teams->modelKeys();
    $report = $service->inspect('nfl', 2026, 'team', $ids);
    $quoted = DB::connection()->getQueryGrammar()->wrapTable('sport_elo_entity_heads');
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use ($quoted, &$writes): void {
        if (str_starts_with(strtolower($query->sql), 'insert into '.strtolower($quoted)) && ++$writes === 2) {
            throw new RuntimeException('Synthetic second head failure');
        }
    });
    expect(fn () => $service->apply('nfl', 2026, 'team', $ids, $report['fingerprint'], 'review-fixture'))->toThrow(RuntimeException::class, 'Synthetic second head failure');
    expect(SportEloSeasonInitialization::count())->toBe(0)->and(SportEloEntityHead::count())->toBe(0);
});

it('records reviewed current legacy evidence without inventing its original model or observation time', function (string $sport) {
    [$home] = coherentLegacyEloBaseline($sport);
    enableSyntheticCoordinatedEloAdmission($sport);
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect($sport, 2026, 'team', [$home->id]);
    expect($report['items'][0]['status'])->toBe('admissible');
    $service->apply($sport, 2026, 'team', [$home->id], $report['fingerprint'], 'review-current-projection');
    $receipt = SportEloSeasonInitialization::sole();
    expect($receipt->baseline_kind)->toBe('reviewed_legacy_current')
        ->and($receipt->evidence['historical_calculation_provenance'])->toBe('unknown_original_model_and_observation_time')
        ->and($receipt->prior_effect_id)->toBeNull()->and(SportEloApplication::count())->toBe(0);
    $again = $service->apply($sport, 2026, 'team', [$home->id], $report['fingerprint'], 'same-source-second-review');
    expect($again['receipt_ids'])->toBe([$receipt->id])->and(SportEloSeasonInitialization::count())->toBe(1);
})->with(['nfl', 'nba', 'mlb', 'cbb', 'wcbb']);

it('refuses incomplete and hidden precision mismatches in legacy history', function (string $change) {
    [$home, $away, $game] = coherentLegacyEloBaseline();
    if ($change === 'pair') {
        EloRating::where('team_id', $away->id)->delete();
    } elseif ($change === 'precision') {
        DB::table('nfl_elo_ratings')->where('team_id', $home->id)->update(['elo_rating' => '1640.04']);
    } else {
        $game->update(['home_score' => null]);
    }
    $report = app(EloBaselineAdmission::class)->inspect('nfl', 2026, 'team', [$home->id]);
    expect($report['items'][0]['status'])->toBe('legacy_unknown')->and(SportEloSeasonInitialization::count())->toBe(0);
})->with(['pair', 'precision', 'missing_score']);

it('bounds and validates the exact source selection', function (string $sport, string $type, array $ids) {
    expect(fn () => app(EloBaselineAdmission::class)->inspect($sport, 2026, $type, $ids))->toThrow(InvalidArgumentException::class);
})->with([['cfb', 'team', [1]], ['wnba', 'team', [1]], ['nfl', 'pitcher', [1]], ['nfl', 'team', []],
    ['nfl', 'team', [1, 1]], ['nfl', 'team', range(1, 101)], ['nfl', 'team', [true]], ['nfl', 'team', [1.5]]]);

it('refuses a corrupted stored admission rather than trusting its unchanged source marker', function (array $change) {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture');
    DB::table('sport_elo_season_initializations')->update($change);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture'))
        ->toThrow(RuntimeException::class, 'conflicts');
    expect(SportEloSeasonInitialization::count())->toBe(1)->and((int) $team->fresh()->elo_rating)->toBe(1500);
})->with([[['rating_after' => '1600.0000']], [['prior_rating' => '1200.0000']],
    [['regression_factor' => '0.3000000']], [['source_history_id' => 999]]]);

it('keeps MLB pitcher identity distinct from a team and records only a current baseline', function () {
    $team = App\Models\MLB\Team::factory()->create(['elo_rating' => 1500]);
    $pitcher = Player::factory()->pitcher()->create(['team_id' => $team->id, 'elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission('mlb');
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('mlb', 2026, 'pitcher', [$pitcher->id]);
    $before = $pitcher->fresh()->getAttributes();
    $service->apply('mlb', 2026, 'pitcher', [$pitcher->id], $report['fingerprint'], 'review-pitcher-default');
    expect(SportEloSeasonInitialization::sole()->entity_type)->toBe('pitcher')
        ->and(SportEloEntityHead::sole()->entity_type)->toBe('pitcher')
        ->and($pitcher->fresh()->getAttributes())->toBe($before)
        ->and(PitcherEloRating::count())->toBe(0)
        ->and(SportEloApplication::count())->toBe(0);
});

it('stores pre-1970 baseline chronology as UTC regardless of a non-UTC application timezone', function () {
    config(['app.timezone' => 'America/Chicago']);
    [$home] = coherentLegacyEloBaseline('nfl', 1965);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 1965, 'team', [$home->id]);
    $service->apply('nfl', 1965, 'team', [$home->id], $report['fingerprint'], 'review-pre1970-current-projection');
    $head = SportEloEntityHead::sole();
    expect($head->last_played_at->format('Y-m-d H:i:s.uP'))->toBe('1965-10-01 12:00:00.000000+00:00')
        ->and($head->getRawOriginal('last_played_at'))->toBe('1965-10-01 12:00:00.000000')
        ->and($head->toArray()['last_played_at'])->toBe('1965-10-01T12:00:00.000000Z');
});

it('binds reviewed MLB pitcher baselines to the exact stored paired history', function () {
    $home = App\Models\MLB\Team::factory()->create();
    $away = App\Models\MLB\Team::factory()->create();
    $game = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 3, 'away_score' => 1,
        'game_date' => '2026-10-01', 'game_time' => '12:00:00']);
    $pitchers = [];
    foreach ([[$home, 1640], [$away, 1360]] as [$team, $rating]) {
        $pitcher = Player::factory()->pitcher()->create(['team_id' => $team->id, 'elo_rating' => $rating]);
        PitcherEloRating::create(['player_id' => $pitcher->id, 'team_id' => $team->id, 'game_id' => $game->id,
            'season' => 2026, 'date' => '2026-10-01', 'elo_rating' => $rating, 'elo_change' => 1, 'games_started' => 1]);
        $pitchers[] = $pitcher;
    }
    enableSyntheticCoordinatedEloAdmission('mlb');
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('mlb', 2026, 'pitcher', [$pitchers[0]->id]);
    expect($report['items'][0]['status'])->toBe('admissible');
    $service->apply('mlb', 2026, 'pitcher', [$pitchers[0]->id], $report['fingerprint'], 'review-pitcher-current');
    $receipt = SportEloSeasonInitialization::sole();
    expect($receipt->source_history_table)->toBe('mlb_pitcher_elo_ratings')
        ->and($receipt->evidence['pair'])->toHaveCount(2)
        ->and($receipt->evidence['historical_calculation_provenance'])->toBe('unknown_original_model_and_observation_time');
});

it('refuses corrupted head chronology even when its source marker still matches', function (array $change) {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture');
    DB::table('sport_elo_entity_heads')->update($change);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-fixture'))
        ->toThrow(RuntimeException::class, 'conflicts');
    expect(SportEloSeasonInitialization::count())->toBe(1)->and((int) $team->fresh()->elo_rating)->toBe(1500);
})->with([[['last_game_id' => 123]], [['last_played_at' => '1965-01-01 00:00:00.000000']]]);

it('refuses an approval fingerprint when the authoritative write target changes', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $target = Mockery::mock(EloWriteTargetIdentity::class);
    $target->shouldReceive('describe')->once()->andReturn(['fingerprint' => str_repeat('a', 64), 'scope' => 'physical_database', 'portable_approval' => true]);
    $target->shouldReceive('describe')->once()->andReturn(['fingerprint' => str_repeat('b', 64), 'scope' => 'physical_database', 'portable_approval' => true]);
    app()->instance(EloWriteTargetIdentity::class, $target);
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$team->id]);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$team->id], $report['fingerprint'], 'review-target-control'))
        ->toThrow(RuntimeException::class);
    expect(SportEloSeasonInitialization::count())->toBe(0)->and(SportEloEntityHead::count())->toBe(0);
});

it('refuses unavailable write identity before creating any admission rows', function () {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    enableSyntheticCoordinatedEloAdmission();
    $target = Mockery::mock(EloWriteTargetIdentity::class);
    $target->shouldReceive('describe')->once()->andThrow(new RuntimeException('Authoritative Elo write-target identity is unavailable.'));
    app()->instance(EloWriteTargetIdentity::class, $target);
    expect(fn () => app(EloBaselineAdmission::class)->apply('nfl', 2026, 'team', [$team->id], str_repeat('a', 64), 'review-unavailable-target'))
        ->toThrow(RuntimeException::class, 'write-target identity is unavailable');
    expect(SportEloSeasonInitialization::count())->toBe(0)->and(SportEloEntityHead::count())->toBe(0);
});

it('binds a reviewed MLB baseline to the observed clock and refuses changed observation identity', function () {
    [$home, $away, $game] = coherentLegacyEloBaseline('mlb');
    $game->update(MlbGameStart::prepare($game,
        ['game_time' => '22:00:00'] + MlbGameStart::observed('2026-10-02T03:00:00Z', [])));
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('mlb', 2026, 'team', [$home->id]);
    expect($report['items'][0]['status'])->toBe('admissible')
        ->and($report['items'][0]['evidence']['game']['played_at_utc'])->toBe('2026-10-02 03:00:00.000000')
        ->and($report['items'][0]['evidence']['game']['start_observation_id'])->toBe($game->start_observation_id)
        ->and(substr($report['items'][0]['evidence']['game']['game_date'], 0, 10))->toBe('2026-10-01');
    $game->newQuery()->whereKey($game->id)->update(['start_observation_id' => '00000000-0000-4000-8000-000000000000']);
    $refused = $service->inspect('mlb', 2026, 'team', [$home->id]);
    expect($refused['items'][0]['status'])->toBe('legacy_unknown')
        ->and(SportEloSeasonInitialization::count())->toBe(0);
});
