<?php

use App\Actions\MLB\CalculateElo;
use App\Models\CBB\Team;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\SportEloApplication;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function rolloverEloFixture(string $sport): array
{
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return in_array($sport, ['nfl', 'nba', 'mlb', 'cbb', 'wcbb'], true) && $protocol === 1;
        }
    });
    config(['elo_integrity.enabled' => true]);
    $definition = app(EloSportRegistry::class)->resolve($sport, 'team');
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-rollover-drain', 'drain_verified_at' => now()->subMinute()]);
    $class = $definition['entity_model'];
    $home = $class::factory()->create(['elo_rating' => 1500]);
    $away = $class::factory()->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect($sport, 2026, 'team', [$home->id, $away->id]);
    $admission->apply($sport, 2026, 'team', [$home->id, $away->id], $report['fingerprint'], 'synthetic-baseline');
    config(['elo_integrity.write_target_fingerprints.'.$sport => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'active']);

    return [$home, $away];
}

it('records an empty-season rollover exactly once instead of relying on any game history marker', function (string $sport) {
    [$home, $away] = rolloverEloFixture($sport);
    expect(Artisan::call($sport.':calculate-elo', ['--season' => 2027, '--regress' => true]))->toBe(0);
    $before = [SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray(), $home->fresh()->getAttributes(), $away->fresh()->getAttributes()];
    expect(Artisan::call($sport.':calculate-elo', ['--season' => 2027, '--regress' => true]))->toBe(0)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(2)
        ->and([SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray(), $home->fresh()->getAttributes(), $away->fresh()->getAttributes()])->toBe($before);
    if ($sport === 'nfl') {
        expect(EloRating::where('season', 2027)->where('week', 0)->count())->toBe(2);
    }
})->with(['nfl', 'cbb', 'wcbb']);

it('rejects a factor change and skipped season while retaining the prior rollover evidence', function () {
    rolloverEloFixture('cbb');
    $service = app(EloSeasonRollover::class);
    $service->execute('cbb', 2027, ['team' => '0.3']);
    $before = [SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()];
    expect(fn () => $service->execute('cbb', 2027, ['team' => '0.4']))->toThrow(RuntimeException::class)
        ->and(fn () => $service->execute('cbb', 2029, ['team' => '0.3']))->toThrow(InvalidArgumentException::class)
        ->and([SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()])->toBe($before);
});

it('refuses a partially admitted roster before creating any season transitions', function () {
    rolloverEloFixture('cbb');
    Team::factory()->create(['elo_rating' => 1500]);
    expect(fn () => app(EloSeasonRollover::class)->execute('cbb', 2027, ['team' => '0.3']))->toThrow(RuntimeException::class);
    expect(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(0)
        ->and(SportEloEntityHead::where('season', 2027)->count())->toBe(0);
});

it('keeps all receipts and projections atomic when a rollover entity write fails', function () {
    [$home, $away] = rolloverEloFixture('nfl');
    $before = [SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()];
    App\Models\NFL\Team::saving(function ($team) use ($away) {
        if ($team->id === $away->id) {
            throw new RuntimeException('synthetic rollover failure');
        }
    });
    try {
        expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']))->toThrow(RuntimeException::class, 'synthetic rollover');
    } finally {
        App\Models\NFL\Team::flushEventListeners();
    }
    expect([SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()])->toBe($before)
        ->and(EloRating::count())->toBe(0)->and(SportEloApplication::count())->toBe(0);
});

it('requires an explicit coordinated MLB season and refuses the old imperative action bypass', function () {
    [$home] = rolloverEloFixture('mlb');
    expect(Artisan::call('mlb:apply-offseason-regression'))->toBe(1)
        ->and(fn () => (new CalculateElo)->applyTeamRegression($home))->toThrow(RuntimeException::class)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(0);
});

it('refuses rollover after a predecessor source correction before changing any entity', function () {
    [$home, $away] = rolloverEloFixture('nfl');
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);
    (new App\Actions\NFL\CalculateElo)->execute($game);
    Game::whereKey($game->id)->update(['away_score' => 30]);
    $before = [SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()];
    expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']))->toThrow(RuntimeException::class, 'predecessor')
        ->and([SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()])->toBe($before);
});

it('offers an NBA zero-factor dry run and applies only the exact reviewed whole plan once', function () {
    [$home, $away] = rolloverEloFixture('nba');
    $arguments = ['sport' => 'nba', '--season' => 2027, '--team-factor' => '0'];
    $before = [SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()];
    expect(Artisan::call('sports:rollover-elo', $arguments))->toBe(0);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($plan['dry_run'])->toBeTrue()->and($plan['factors']['team'])->toBe('0.0000000')
        ->and([SportEloEntityHead::get()->toArray(), SportEloSeasonInitialization::get()->toArray()])->toBe($before);
    expect(Artisan::call('sports:rollover-elo', [...$arguments, '--apply' => true, '--fingerprint' => $plan['fingerprint']]))->toBe(0)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(2)
        ->and($home->fresh()->elo_rating)->toBe(1500)->and($away->fresh()->elo_rating)->toBe(1500);
    expect(Artisan::call('sports:rollover-elo', $arguments))->toBe(0);
    $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(Artisan::call('sports:rollover-elo', [...$arguments, '--apply' => true, '--fingerprint' => $again['fingerprint']]))->toBe(0)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(2);
});

it('refuses stale roster context or factor fingerprints before an operator transition', function (bool $factorChanged) {
    [$home] = rolloverEloFixture('nba');
    $arguments = ['sport' => 'nba', '--season' => 2027, '--team-factor' => '0'];
    expect(Artisan::call('sports:rollover-elo', $arguments))->toBe(0);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    if ($factorChanged) {
        $arguments['--team-factor'] = '0.3';
    } else {
        App\Models\NBA\Team::factory()->create(['elo_rating' => 1500]);
    }
    expect(Artisan::call('sports:rollover-elo', [...$arguments, '--apply' => true, '--fingerprint' => $plan['fingerprint']]))->toBe(1)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(0)
        ->and(SportEloEntityHead::where('season', 2027)->count())->toBe(0);
})->with([true, false]);
