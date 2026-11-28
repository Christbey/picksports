<?php

use App\Actions\NFL\CalculateElo;
use App\Exceptions\EloAdmissionRefused;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\MLB\MlbGameStart;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function coordinatedEloFixture(string $sport = 'nfl', int $season = 2026): array
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
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'admitting', 'protocol_version' => 1,
        'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
        'drain_reference' => 'synthetic-drained-adapter-fixture', 'drain_verified_at' => now()->subMinute()]);
    $teamClass = $definition['entity_model'];
    $gameClass = $definition['game_model'];
    $home = $teamClass::factory()->create(['elo_rating' => 1500]);
    $away = $teamClass::factory()->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect($sport, $season, 'team', [$home->id, $away->id]);
    $admission->apply($sport, $season, 'team', [$home->id, $away->id], $report['fingerprint'], 'synthetic-reviewed-baseline');
    config(['elo_integrity.write_target_fingerprints.'.$sport => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'active']);
    $gameFactory = $gameClass::factory();
    if ($sport === 'mlb') {
        $gameFactory = $gameFactory->withSyntheticWallStart('UTC');
    }
    $game = $gameFactory->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => $season.'-10-01', 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);

    return [$home, $away, $game];
}

it('refuses active application when the gate is paused without touching any projection', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes()];
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting']);
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(RuntimeException::class);
    expect([$home->fresh()->getAttributes(), $away->fresh()->getAttributes()])->toBe($before)
        ->and(EloRating::count())->toBe(0)->and(SportEloApplication::count())->toBe(0);
});

it('refuses a partial legacy game pair rather than silently treating it as completed', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    EloRating::create(['team_id' => $home->id, 'game_id' => $game->id, 'season' => 2026, 'week' => 1,
        'date' => '2026-10-01', 'elo_rating' => 1510, 'elo_change' => 10]);
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(RuntimeException::class);
    expect(EloRating::count())->toBe(1)->and($home->fresh()->elo_rating)->toBe(1500)
        ->and($away->fresh()->elo_rating)->toBe(1500);
});

it('uses the locked current game instead of stale caller scores and relations', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    $game->load(['homeTeam', 'awayTeam']);
    Game::whereKey($game->id)->update(['home_score' => 10, 'away_score' => 30]);
    (new CalculateElo)->execute($game);
    expect((float) $home->fresh()->elo_rating)->toBeLessThan(1500)
        ->and((float) $away->fresh()->elo_rating)->toBeGreaterThan(1500)
        ->and(SportEloApplication::count())->toBe(1);
});

it('refuses changed source after a matching immutable game receipt', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    (new CalculateElo)->execute($game);
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), EloRating::get()->toArray()];
    Game::whereKey($game->id)->update(['away_score' => 40]);
    expect(fn () => (new CalculateElo)->execute($game->fresh()))->toThrow(RuntimeException::class);
    expect([$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), EloRating::get()->toArray()])->toBe($before)
        ->and(SportEloApplication::count())->toBe(1);
});

it('refuses an older game after a later head without rolling current ratings backwards', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    (new CalculateElo)->execute($game);
    $old = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-01', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()];
    expect(fn () => (new CalculateElo)->execute($old))->toThrow(RuntimeException::class);
    expect([$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()])->toBe($before)
        ->and(EloRating::count())->toBe(2)->and(SportEloApplication::count())->toBe(1);
});

it('atomically publishes complete sport pairs and reuses matching receipts without double updates', function (string $sport) {
    [$home, $away, $game] = coordinatedEloFixture($sport);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\CalculateElo';
    $first = (new $action)->execute($game);
    $definition = app(EloSportRegistry::class)->resolve($sport, 'team');
    $history = $definition['history_model'];
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), $history::get()->toArray(), SportEloEntityHead::get()->toArray()];
    $again = (new $action)->execute($game, skipIfExists: false);
    expect($first['skipped'])->toBeFalse()->and($again['skipped'])->toBeTrue()
        ->and(SportEloApplication::count())->toBe(1)->and($history::count())->toBe(2)
        ->and([$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), $history::get()->toArray(), SportEloEntityHead::get()->toArray()])->toBe($before);
})->with(['nfl', 'nba', 'mlb', 'cbb', 'wcbb']);

it('rolls back both teams histories and receipts if the second history insert fails', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()];
    EloRating::creating(function ($rating) use ($away) {
        if ((int) $rating->team_id === (int) $away->id) {
            throw new RuntimeException('synthetic second history write failure');
        }
    });
    try {
        expect(fn () => (new CalculateElo)->execute($game))->toThrow(RuntimeException::class, 'synthetic second');
    } finally {
        EloRating::flushEventListeners();
    }
    expect([$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()])->toBe($before)
        ->and(EloRating::count())->toBe(0)->and(SportEloApplication::count())->toBe(0);
});

it('refuses mismatched target version or unadmitted projection without falling back', function (string $conflict) {
    [$home, $away, $game] = coordinatedEloFixture();
    if ($conflict === 'target') {
        config(['elo_integrity.write_target_fingerprints.nfl' => str_repeat('a', 64)]);
    } elseif ($conflict === 'version') {
        SportEloWriterGate::whereKey('nfl')->update(['calculation_version' => 'another-version']);
    } else {
        Team::whereKey($home->id)->update(['elo_rating' => 1600]);
    }
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(RuntimeException::class);
    expect(EloRating::count())->toBe(0)->and(SportEloApplication::count())->toBe(0);
})->with(['target', 'version', 'projection']);

function coordinatedMlbPitchers(array $fixture): array
{
    [$home, $away, $game] = $fixture;
    $homePitcher = Player::factory()->pitcher()->create(['team_id' => $home->id, 'elo_rating' => 1500]);
    $awayPitcher = Player::factory()->pitcher()->create(['team_id' => $away->id, 'elo_rating' => 1500]);
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'admitting']);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('mlb', 2026, 'pitcher', [$homePitcher->id, $awayPitcher->id]);
    $admission->apply('mlb', 2026, 'pitcher', [$homePitcher->id, $awayPitcher->id], $report['fingerprint'], 'synthetic-pitcher-admission');
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'active']);
    foreach ([[$home, $homePitcher], [$away, $awayPitcher]] as [$team, $pitcher]) {
        PlayerStat::factory()->pitching()->create(['game_id' => $game->id, 'team_id' => $team->id,
            'player_id' => $pitcher->id, 'innings_pitched' => '6.0']);
    }

    return [$homePitcher, $awayPitcher];
}

it('publishes MLB team and pitcher pairs with durable counters in one transaction', function () {
    $fixture = coordinatedEloFixture('mlb');
    [$homePitcher, $awayPitcher] = coordinatedMlbPitchers($fixture);
    $game = $fixture[2];
    $first = (new App\Actions\MLB\CalculateElo)->execute($game);
    $again = (new App\Actions\MLB\CalculateElo)->execute($game);
    expect($first['skipped'])->toBeFalse()->and($again['skipped'])->toBeTrue()
        ->and(App\Models\MLB\EloRating::count())->toBe(2)->and(PitcherEloRating::count())->toBe(2)
        ->and(PitcherEloRating::pluck('games_started')->all())->toBe([1, 1])
        ->and(SportEloApplicationEffect::count())->toBe(4)
        ->and((float) $homePitcher->fresh()->elo_rating)->toBeGreaterThan(1500)
        ->and((float) $awayPitcher->fresh()->elo_rating)->toBeLessThan(1500);
});

it('rolls back MLB team effects when a pitcher history write fails', function () {
    $fixture = coordinatedEloFixture('mlb');
    coordinatedMlbPitchers($fixture);
    $before = SportEloEntityHead::get()->toArray();
    PitcherEloRating::creating(fn () => throw new RuntimeException('synthetic pitcher failure'));
    try {
        expect(fn () => (new App\Actions\MLB\CalculateElo)->execute($fixture[2]))->toThrow(RuntimeException::class, 'synthetic pitcher');
    } finally {
        PitcherEloRating::flushEventListeners();
    }
    expect(SportEloEntityHead::get()->toArray())->toBe($before)
        ->and(App\Models\MLB\EloRating::count())->toBe(0)->and(PitcherEloRating::count())->toBe(0)
        ->and(SportEloApplication::count())->toBe(0);
});

it('refuses partial or tied MLB pitching identities before any team publication', function (bool $partial) {
    $fixture = coordinatedEloFixture('mlb');
    [$homePitcher, $awayPitcher] = coordinatedMlbPitchers($fixture);
    if ($partial) {
        PlayerStat::where('player_id', $awayPitcher->id)->delete();
    } else {
        $other = Player::factory()->pitcher()->create(['team_id' => $fixture[0]->id]);
        PlayerStat::factory()->pitching()->create(['game_id' => $fixture[2]->id, 'team_id' => $fixture[0]->id,
            'player_id' => $other->id, 'innings_pitched' => '6.0']);
    }
    expect(fn () => (new App\Actions\MLB\CalculateElo)->execute($fixture[2]))->toThrow(RuntimeException::class)
        ->and(App\Models\MLB\EloRating::count())->toBe(0)->and(SportEloApplication::count())->toBe(0);
})->with([true, false]);

it('refuses to extend a head after its preceding result was corrected', function (string $correction) {
    [$home, $away, $game] = coordinatedEloFixture();
    (new CalculateElo)->execute($game);
    $next = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    Game::whereKey($game->id)->update($correction === 'score' ? ['away_score' => 40] : ['espn_event_id' => '9999999999']);
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()];
    expect(fn () => (new CalculateElo)->execute($next))->toThrow(RuntimeException::class, 'predecessor')
        ->and([$home->fresh()->getAttributes(), $away->fresh()->getAttributes(), SportEloEntityHead::get()->toArray()])->toBe($before)
        ->and(EloRating::count())->toBe(2)->and(SportEloApplication::count())->toBe(1);
})->with(['score', 'provider']);

it('refuses corrupted effective fields even when the application marker is unchanged', function (string $corruption) {
    [$home, $away, $game] = coordinatedEloFixture();
    (new CalculateElo)->execute($game);
    if ($corruption === 'effect') {
        DB::table('sport_elo_application_effects')->where('entity_type', 'team')->where('entity_id', $home->id)->update(['rating_before' => 1499]);
    } elseif ($corruption === 'history') {
        EloRating::where('team_id', $home->id)->update(['elo_change' => 999]);
    } else {
        SportEloEntityHead::where('entity_id', $home->id)->update(['last_played_at' => '2026-09-01 00:00:00.000000']);
    }
    $before = [$home->fresh()->getAttributes(), $away->fresh()->getAttributes()];
    if ($corruption === 'head') {
        $next = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
            'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
            'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
        expect(fn () => (new CalculateElo)->execute($next))->toThrow(RuntimeException::class);
    } else {
        expect(fn () => (new CalculateElo)->execute($game))->toThrow(RuntimeException::class);
    }
    expect([$home->fresh()->getAttributes(), $away->fresh()->getAttributes()])->toBe($before)
        ->and(SportEloApplication::count())->toBe(1);
})->with(['effect', 'history', 'head']);

it('preserves the effect chain through a season rollover and another game', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    (new CalculateElo)->execute($game);
    $priorEffects = SportEloApplicationEffect::pluck('id', 'entity_id');
    app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']);
    $next = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2027, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2027-10-01', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    (new CalculateElo)->execute($next);
    foreach (SportEloEntityHead::get() as $head) {
        $effect = SportEloApplicationEffect::findOrFail($head->last_effect_id);
        expect((int) $effect->previous_effect_id)->toBe((int) $priorEffects[$head->entity_id])
            ->and($head->season)->toBe(2027);
    }
    expect(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(6);
});

it('orders coordinated command games by observed time before provider row identity', function () {
    [$home, $away, $later] = coordinatedEloFixture();
    Game::whereKey($later->id)->update(['game_time' => '20:00:00']);
    $earlier = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '12:00:00', 'home_score' => 10, 'away_score' => 20]);
    expect(Artisan::call('nfl:calculate-elo', ['--season' => 2026]))->toBe(0)
        ->and(SportEloApplication::orderBy('id')->pluck('game_id')->all())->toBe([$earlier->id, $later->id])
        ->and(EloRating::count())->toBe(4);
});

it('rolls over MLB teams and admitted pitchers exactly once while leaving nonpitchers unchanged', function () {
    $fixture = coordinatedEloFixture('mlb');
    [$homePitcher, $awayPitcher] = coordinatedMlbPitchers($fixture);
    (new App\Actions\MLB\CalculateElo)->execute($fixture[2]);
    $batter = Player::factory()->create(['team_id' => $fixture[0]->id, 'position' => '1B', 'elo_rating' => 1800]);
    $before = $batter->fresh()->getAttributes();
    $arguments = ['--season' => 2027, '--team-factor' => '0.33', '--pitcher-factor' => '0.40'];
    expect(Artisan::call('mlb:apply-offseason-regression', $arguments))->toBe(0)
        ->and(Artisan::call('mlb:apply-offseason-regression', $arguments))->toBe(0)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(4)
        ->and($batter->fresh()->getAttributes())->toBe($before)
        ->and(SportEloEntityHead::where('season', 2027)->count())->toBe(4);
});

it('refuses to skip an earlier known final event or its incomplete facts', function (string $missing) {
    [$home, $away, $pending] = coordinatedEloFixture();
    if ($missing === 'score') {
        Game::whereKey($pending->id)->update(['home_score' => null]);
    }
    if ($missing === 'clock') {
        Game::whereKey($pending->id)->update(['game_time' => '25:00:00']);
    }
    if ($missing === 'type') {
        Game::whereKey($pending->id)->update(['season_type' => 99]);
    }
    $later = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    expect(fn () => (new CalculateElo)->execute($later))->toThrow(RuntimeException::class)
        ->and(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0);
})->with(['none', 'score', 'clock', 'type']);

it('refuses to roll over while a known preceding-season final remains after the admitted prefix', function () {
    coordinatedEloFixture();
    expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']))->toThrow(RuntimeException::class)
        ->and(SportEloSeasonInitialization::where('kind', 'season_rollover')->count())->toBe(0);
});

function reviewedEloPrefixFixture(bool $oneSided = false): array
{
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nfl' && $protocol === 1;
        }
    });
    config(['elo_integrity.enabled' => true]);
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-prefix-drain', 'drain_verified_at' => now()->subMinute()]);
    $teams = collect(range(1, 4))->map(fn () => Team::factory()->create(['elo_rating' => 1500]));
    $anchors = [];
    foreach ([[$teams[0], $teams[2], '18:00:00'], [$teams[1], $teams[3], $oneSided ? '12:00:00' : '18:00:00']] as [$home, $away, $time]) {
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
            'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
            'game_date' => '2026-10-01', 'game_time' => $time, 'home_score' => 20, 'away_score' => 10]);
        foreach ([$home, $away] as $team) {
            EloRating::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => 2026, 'week' => 1,
                'date' => '2026-10-01', 'elo_rating' => 1500, 'elo_change' => 0]);
        }
        $anchors[] = $game;
    }
    $admission = app(EloBaselineAdmission::class);
    $ids = $teams->pluck('id')->all();
    $plan = $admission->inspect('nfl', 2026, 'team', $ids);
    $admission->apply('nfl', 2026, 'team', $ids, $plan['fingerprint'], 'synthetic-reviewed-prefix');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'active']);
    config(['elo_integrity.write_target_fingerprints.nfl' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    $before = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '16:00:00', 'home_score' => 10, 'away_score' => 20]);

    return [$teams, $anchors, $before];
}

it('keeps a no-write distinction for both reviewed cutoffs without inventing old game receipts', function () {
    [$teams, $anchors, $before] = reviewedEloPrefixFixture();
    $image = [EloRating::get()->toArray(), SportEloEntityHead::get()->toArray()];
    $result = (new CalculateElo)->execute($before);
    expect($result['skipped'])->toBeTrue()->and($result['reason'])->toBe('before_reviewed_baseline_cutoff')
        ->and(SportEloApplication::count())->toBe(0)->and([EloRating::get()->toArray(), SportEloEntityHead::get()->toArray()])->toBe($image);
});

it('refuses one-sided reviewed cutoffs rather than treating later mutable heads as historical approval', function () {
    [$teams, $anchors, $between] = reviewedEloPrefixFixture(true);
    $before = SportEloEntityHead::get()->toArray();
    expect(fn () => (new CalculateElo)->execute($between))->toThrow(RuntimeException::class)
        ->and(SportEloEntityHead::get()->toArray())->toBe($before)->and(SportEloApplication::count())->toBe(0);
});

it('revalidates the exact immutable review source before returning the no-write cutoff status', function () {
    [$teams, $anchors, $before] = reviewedEloPrefixFixture();
    Game::whereKey($anchors[0]->id)->update(['away_score' => 99]);
    expect(fn () => (new CalculateElo)->execute($before))->toThrow(RuntimeException::class)
        ->and(SportEloApplication::count())->toBe(0);
});

it('does not turn a later mutable head into a one-sided reviewed baseline cutoff', function () {
    [$teams, $anchors, $between] = reviewedEloPrefixFixture(true);
    Game::whereKey($between->id)->update(['status' => 'STATUS_SCHEDULED']);
    $later = Game::factory()->create(['home_team_id' => $teams[1]->id, 'away_team_id' => $teams[3]->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '20:00:00', 'home_score' => 10, 'away_score' => 20]);
    (new CalculateElo)->execute($later);
    Game::whereKey($between->id)->update(['status' => 'STATUS_FINAL']);
    expect(fn () => (new CalculateElo)->execute($between))->toThrow(RuntimeException::class)
        ->and(SportEloApplication::count())->toBe(1);
});

it('does not excuse a newly discovered post-cutoff gap just because a head is later', function () {
    [$teams, $anchors, $old] = reviewedEloPrefixFixture();
    $later = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '20:00:00', 'home_score' => 10, 'away_score' => 20]);
    (new CalculateElo)->execute($later);
    Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '19:00:00', 'home_score' => 20, 'away_score' => 10]);
    $next = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '22:00:00', 'home_score' => 20, 'away_score' => 10]);
    expect(fn () => (new CalculateElo)->execute($next))->toThrow(RuntimeException::class, 'unreceipted_prior_final')
        ->and(SportEloApplication::count())->toBe(1);
});

it('does not fabricate old game coverage when a reviewed history pair changed', function () {
    [$teams, $anchors, $before] = reviewedEloPrefixFixture();
    EloRating::where('game_id', $anchors[0]->id)->where('team_id', $teams[0]->id)->update(['elo_rating' => 1501]);
    expect(fn () => (new CalculateElo)->execute($before))->toThrow(RuntimeException::class, 'history pair changed')
        ->and(SportEloApplication::count())->toBe(0);
});

it('keeps explicit zero scores and UTC midnight available without inventing missing facts', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    Game::whereKey($game->id)->update(['home_score' => 0, 'away_score' => 0, 'game_time' => '00:00:00']);
    $result = (new CalculateElo)->execute($game);
    expect($result['skipped'])->toBeFalse()->and(SportEloApplication::sole()->source_snapshot['observed_source']['game']['home_score'])->toBe(0)
        ->and(SportEloApplication::sole()->source_snapshot['observed_source']['played_at_utc'])->toBe('2026-10-01 00:00:00.000000');
});

it('excludes only a known noncompetitive type without requiring irrelevant final measurements', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    Game::whereKey($game->id)->update(['season_type' => 1, 'home_score' => null, 'game_time' => '25:00:00']);
    $result = (new CalculateElo)->execute($game);
    expect($result['skipped'])->toBeTrue()->and($result['reason'])->toBe('excluded_season_type')
        ->and(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0);
});

it('does not let an unrelated later incomplete final block an earlier valid single game', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '25:00:00', 'home_score' => null, 'away_score' => 20]);
    expect((new CalculateElo)->execute($game)['skipped'])->toBeFalse()
        ->and(SportEloApplication::count())->toBe(1);
});

it('uses the canonical final-status set in the guarded normal command', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    Game::whereKey($game->id)->update(['status' => 'STATUS_FULL_TIME']);
    expect(Artisan::call('nfl:calculate-elo', ['--season' => 2026]))->toBe(0)
        ->and(SportEloApplication::count())->toBe(1);
});

it('preflights unknown command types before applying any of its otherwise valid finals', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 2, 'season_type' => 99, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);
    expect(Artisan::call('nfl:calculate-elo', ['--season' => 2026]))->toBe(1)
        ->and(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0);
});

it('aborts rather than truncates a source set above its declared game budget and reports considered counts', function () {
    [$home, $away, $game] = coordinatedEloFixture();
    for ($offset = 0; $offset < 10000; $offset += 500) {
        $rows = [];
        for ($index = $offset; $index < $offset + 500; $index++) {
            $rows[] = ['espn_event_id' => 'budget-'.$index, 'season' => 2026, 'week' => 1, 'season_type' => 2,
                'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '00:00:00',
                'home_team_id' => $home->id, 'away_team_id' => $away->id, 'home_score' => 0, 'away_score' => 0];
        }
        DB::table('nfl_games')->insert($rows);
    }
    try {
        (new CalculateElo)->execute($game);
        $this->fail('An over-budget source set was applied.');
    } catch (EloAdmissionRefused $exception) {
        expect($exception->reason)->toBe('game_source_budget_exceeded')
            ->and($exception->considered['games'])->toBe(10001)->and($exception->limits['games'])->toBe(10000);
    }
    expect(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0)
        ->and(SportEloEntityHead::where('generation', 1)->count())->toBe(2);
});

it('checks older direct post-cutoff source receipts as well as the immediate predecessor', function () {
    [$home, $away, $first] = coordinatedEloFixture();
    (new CalculateElo)->execute($first);
    $second = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 2, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-08', 'game_time' => '18:00:00', 'home_score' => 10, 'away_score' => 20]);
    (new CalculateElo)->execute($second);
    Game::whereKey($first->id)->update(['away_score' => 99]);
    $third = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 3, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-15', 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);
    expect(fn () => (new CalculateElo)->execute($third))->toThrow(RuntimeException::class, 'corrected_prior_final_source')
        ->and(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('rejects 100001 SQL observations before materializing their value rows', function () {
    [$home, $away, $target] = coordinatedEloFixture('mlb');
    $playerRows = [];
    for ($index = 0; $index < 100; $index++) {
        $playerRows[] = ['espn_id' => 'stat-budget-player-'.$index, 'team_id' => $index < 50 ? $home->id : $away->id,
            'first_name' => 'Synthetic', 'last_name' => 'Budget', 'full_name' => 'Synthetic budget fixture', 'position' => 'P'];
    }
    DB::table('mlb_players')->insert($playerRows);
    $players = DB::table('mlb_players')->where('espn_id', 'like', 'stat-budget-player-%')->orderBy('id')->get(['id', 'team_id']);
    $gameRows = [];
    for ($index = 0; $index < 1001; $index++) {
        $gameRows[] = ['espn_event_id' => 'stat-budget-game-'.$index, 'season' => 2026, 'week' => 1, 'season_type' => 2,
            'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '00:00:00',
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'home_score' => 0, 'away_score' => 0];
    }
    foreach (array_chunk($gameRows, 500) as $chunk) {
        DB::table('mlb_games')->insert($chunk);
    }
    unset($gameRows);
    $games = DB::table('mlb_games')->where('espn_event_id', 'like', 'stat-budget-game-%')->orderBy('id')->pluck('id');
    $batch = [];
    foreach ($games as $gameIndex => $id) {
        foreach ($players as $playerIndex => $player) {
            if ($gameIndex === 1000 && $playerIndex > 0) {
                break;
            }
            $batch[] = ['game_id' => $id, 'player_id' => $player->id, 'team_id' => $player->team_id,
                'stat_type' => 'pitching', 'innings_pitched' => (string) (($playerIndex % 50) + 1)];
            if (count($batch) === 500) {
                DB::table('mlb_player_stats')->insert($batch);
                $batch = [];
            }
        }
    }
    if ($batch !== []) {
        DB::table('mlb_player_stats')->insert($batch);
    }
    unset($batch);
    try {
        (new App\Actions\MLB\CalculateElo)->execute($target);
        $this->fail('An over-budget SQL observation set was accepted.');
    } catch (EloAdmissionRefused $exception) {
        expect($exception->reason)->toBe('pitching_source_budget_exceeded')
            ->and($exception->considered['pitching_observations_considered'])->toBe(100001)
            ->and($exception->limits['pitching_observations'])->toBe(100000);
    }
    expect(SportEloApplication::count())->toBe(0)->and(App\Models\MLB\EloRating::count())->toBe(0)
        ->and(PitcherEloRating::count())->toBe(0);
});

it('retains MLB source calendar history while ordering by a next-day observed UTC instant', function () {
    $fixture = coordinatedEloFixture('mlb');
    $game = $fixture[2];
    $game->update(MlbGameStart::prepare($game,
        ['game_time' => '22:00:00'] + MlbGameStart::observed('2026-10-02T03:00:00Z', [])));
    coordinatedMlbPitchers($fixture);
    $first = (new App\Actions\MLB\CalculateElo)->execute($game);
    $again = (new App\Actions\MLB\CalculateElo)->execute($game);
    expect($first['skipped'])->toBeFalse()->and($again['skipped'])->toBeTrue()
        ->and(SportEloApplication::sole()->played_at->format('Y-m-d H:i:s'))->toBe('2026-10-02 03:00:00')
        ->and(App\Models\MLB\EloRating::pluck('date')->map(fn ($date) => substr((string) $date, 0, 10))->all())->toBe(['2026-10-01', '2026-10-01'])
        ->and(PitcherEloRating::pluck('date')->map(fn ($date) => substr((string) $date, 0, 10))->all())->toBe(['2026-10-01', '2026-10-01']);
});
