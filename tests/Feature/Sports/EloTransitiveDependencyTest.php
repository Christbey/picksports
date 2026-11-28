<?php

use App\Actions\NFL\CalculateElo;
use App\Exceptions\EloAdmissionRefused;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloEntityHead;
use App\Models\SportEloWriterGate;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function transitiveEloFixture(int $season = 2026, string $sport = 'nfl'): array
{
    Http::preventStrayRequests();
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
        'drain_reference' => 'synthetic-transitive-drain-only', 'drain_verified_at' => now()->subMinute()]);
    $teamClass = $definition['entity_model'];
    $teams = $teamClass::factory()->count(4)->create(['elo_rating' => 1500])->all();
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect($sport, $season, 'team', array_map(fn ($team) => $team->id, $teams));
    $admission->apply($sport, $season, 'team', array_map(fn ($team) => $team->id, $teams), $report['fingerprint'], 'synthetic-reviewed-default');
    config(['elo_integrity.write_target_fingerprints.'.$sport => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey($sport)->update(['mode' => 'active']);

    return $teams;
}

function transitiveEloGame(Model $home, Model $away, int $season, int $week): Model
{
    $gameClass = substr($home::class, 0, -4).'Game';

    $gameFactory = $gameClass::factory();
    if ($gameClass === App\Models\MLB\Game::class) {
        $gameFactory = $gameFactory->withSyntheticWallStart('UTC');
    }

    return $gameFactory->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'season_type' => '2', 'week' => $week,
        'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 10,
        'game_date' => sprintf('%d-10-%02d', $season, $week), 'game_time' => '18:00:00']);
}

it('refuses downstream extension after a source correction only in an opponent input chain', function () {
    [$a, $b, $c, $d] = transitiveEloFixture();
    $bc = transitiveEloGame($b, $c, 2026, 1);
    (new CalculateElo)->execute($bc);
    $ab = transitiveEloGame($a, $b, 2026, 8);
    (new CalculateElo)->execute($ab);
    $ad = transitiveEloGame($a, $d, 2026, 15);
    Game::whereKey($bc->id)->update(['away_score' => 40]);
    $before = [Team::orderBy('id')->get()->toArray(), SportEloEntityHead::orderBy('id')->get()->toArray(), EloRating::orderBy('id')->get()->toArray()];
    expect(fn () => (new CalculateElo)->execute($ad))->toThrow(RuntimeException::class);
    expect([Team::orderBy('id')->get()->toArray(), SportEloEntityHead::orderBy('id')->get()->toArray(), EloRating::orderBy('id')->get()->toArray()])->toBe($before)
        ->and(SportEloApplication::count())->toBe(2);
});

it('refuses receipt reuse whose opponent input ancestry was corrected', function () {
    [$a, $b, $c] = transitiveEloFixture();
    $bc = transitiveEloGame($b, $c, 2026, 1);
    (new CalculateElo)->execute($bc);
    $ab = transitiveEloGame($a, $b, 2026, 8);
    (new CalculateElo)->execute($ab);
    Game::whereKey($bc->id)->update(['away_score' => 40]);
    expect(fn () => (new CalculateElo)->execute($ab->fresh()))->toThrow(RuntimeException::class);
    expect(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('refuses rollover when a corrected ancestor is hidden behind a prior season initialization', function () {
    [$a, $b, $c, $d] = transitiveEloFixture(2024);
    $bc = transitiveEloGame($b, $c, 2024, 1);
    (new CalculateElo)->execute($bc);
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2024, 8));
    app(EloSeasonRollover::class)->execute('nfl', 2025, ['team' => '0.3']);
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2025, 1));
    (new CalculateElo)->execute(transitiveEloGame($c, $d, 2025, 8));
    Game::whereKey($bc->id)->update(['away_score' => 40]);
    $before = [Team::orderBy('id')->get()->toArray(), SportEloEntityHead::orderBy('id')->get()->toArray(), EloRating::orderBy('id')->get()->toArray()];
    expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2026, ['team' => '0.3']))->toThrow(RuntimeException::class);
    expect([Team::orderBy('id')->get()->toArray(), SportEloEntityHead::orderBy('id')->get()->toArray(), EloRating::orderBy('id')->get()->toArray()])->toBe($before);
});

it('extends an unchanged complete opponent chain without fabricating or rewriting prior receipts', function () {
    [$a, $b, $c, $d] = transitiveEloFixture();
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2026, 1));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 8));
    $before = SportEloApplication::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    (new CalculateElo)->execute(transitiveEloGame($a, $d, 2026, 15));
    expect(SportEloApplication::orderBy('id')->limit(2)->get()->map(fn ($row) => $row->getRawOriginal())->all())->toBe($before)
        ->and(SportEloApplication::count())->toBe(3)->and(EloRating::count())->toBe(6);
});

it('refuses a transitive application sentinel without changing admitted projections', function () {
    config(['elo_integrity.dependency_limits.applications' => 1]);
    [$a, $b, $c, $d] = transitiveEloFixture();
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2026, 1));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 8));
    $game = transitiveEloGame($a, $d, 2026, 15);
    $before = DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all();
    try {
        (new CalculateElo)->execute($game);
        test()->fail('Expected bounded dependency refusal.');
    } catch (EloAdmissionRefused $error) {
        expect($error->reason)->toBe('dependency_applications_budget')
            ->and($error->considered['dependency_applications'])->toBe(2)
            ->and($error->limits['applications'])->toBe(1);
    }
    expect(DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all())->toEqual($before)
        ->and(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('refuses oversized stored dependency bytes before selecting their JSON bodies', function () {
    [$a, $b, $c] = transitiveEloFixture();
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 1));
    $next = transitiveEloGame($a, $c, 2026, 8);
    // Privileged corruption simulates damaged retained storage, not an allowed model write.
    DB::table('sport_elo_applications')->update(['source_snapshot' => json_encode(['oversized' => str_repeat('x', 65536)])]);
    $bodyReads = 0;
    DB::listen(function ($query) use (&$bodyReads) {
        $sql = strtolower(str_replace(['`', '"'], '', $query->sql));
        if (str_contains($sql, 'select * from sport_elo_applications')) {
            $bodyReads++;
        }
    });
    expect(fn () => (new CalculateElo)->execute($next))->toThrow(EloAdmissionRefused::class, 'dependency_receipt_byte_budget');
    expect($bodyReads)->toBe(0)->and(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2);
});

it('refuses cumulative receipt bytes without publishing a truncated dependency graph', function () {
    config(['elo_integrity.dependency_limits.total_receipt_bytes' => 1]);
    [$a, $b] = transitiveEloFixture();
    $game = transitiveEloGame($a, $b, 2026, 1);
    expect(fn () => (new CalculateElo)->execute($game))->toThrow(EloAdmissionRefused::class, 'dependency_total_receipt_byte_budget');
    expect(SportEloApplication::count())->toBe(0)->and(EloRating::count())->toBe(0)
        ->and(Team::where('elo_rating', 1500)->count())->toBe(4);
});

it('retains dependency ancestry across an otherwise empty intervening season', function () {
    [$a, $b, $c, $d] = transitiveEloFixture(2024);
    $game = transitiveEloGame($b, $c, 2024, 1);
    (new CalculateElo)->execute($game);
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2024, 8));
    (new CalculateElo)->execute(transitiveEloGame($c, $d, 2024, 15));
    app(EloSeasonRollover::class)->execute('nfl', 2025, ['team' => '0.3']);
    app(EloSeasonRollover::class)->execute('nfl', 2026, ['team' => '0.3']);
    $before = EloRating::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    Game::whereKey($game->id)->update(['away_score' => 40]);
    expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']))->toThrow(EloAdmissionRefused::class);
    expect(SportEloApplication::count())->toBe(3)
        ->and(EloRating::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all())->toBe($before)
        ->and(SportEloEntityHead::where('season', 2026)->count())->toBe(4);
});

it('validates complete opponent input closure for every guarded sport', function (string $sport) {
    [$a, $b, $c, $d] = transitiveEloFixture(2026, $sport);
    $calculator = 'App\\Actions\\'.strtoupper($sport).'\\CalculateElo';
    $ancestor = transitiveEloGame($b, $c, 2026, 1);
    (new $calculator)->execute($ancestor);
    (new $calculator)->execute(transitiveEloGame($a, $b, 2026, 8));
    $target = transitiveEloGame($a, $d, 2026, 15);
    (new $calculator)->execute($target);
    expect(SportEloApplication::where('sport', $sport)->count())->toBe(3);
    $ancestor->getConnection()->table($ancestor->getTable())->where('id', $ancestor->id)->update(['away_score' => 40]);
    $before = DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all();
    expect(fn () => (new $calculator)->execute($target->fresh()))->toThrow(RuntimeException::class);
    expect(DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all())->toEqual($before)
        ->and(SportEloApplication::where('sport', $sport)->count())->toBe(3);
})->with(['nfl', 'nba', 'mlb', 'cbb', 'wcbb']);

it('refuses a newly known earlier final confined to an ancestor opponent', function () {
    [$a, $b, $c, $d] = transitiveEloFixture();
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2026, 8));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 15));
    // This source did not exist when the native B-C and A-B inputs were admitted.
    transitiveEloGame($b, $c, 2026, 1);
    $target = transitiveEloGame($a, $d, 2026, 22);
    $before = DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all();
    expect(fn () => (new CalculateElo)->execute($target))->toThrow(EloAdmissionRefused::class);
    expect(DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all())->toEqual($before)
        ->and(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('does not make later ancestor-only finals retroactive dependencies of an unchanged input', function () {
    [$a, $b, $c, $d] = transitiveEloFixture();
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2026, 1));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 8));
    transitiveEloGame($b, $c, 2026, 15);
    (new CalculateElo)->execute(transitiveEloGame($a, $d, 2026, 22));
    expect(SportEloApplication::count())->toBe(3)->and(EloRating::count())->toBe(6);
});

it('retains prior-season ancestor completeness after rollover', function () {
    [$a, $b, $c, $d] = transitiveEloFixture(2024);
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2024, 8));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2024, 15));
    app(EloSeasonRollover::class)->execute('nfl', 2025, ['team' => '0.3']);
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2025, 1));
    transitiveEloGame($b, $c, 2024, 1);
    $target = transitiveEloGame($a, $d, 2025, 8);
    $before = DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all();
    expect(fn () => (new CalculateElo)->execute($target))->toThrow(EloAdmissionRefused::class);
    expect(DB::table('sport_elo_entity_heads')->orderBy('id')->get()->all())->toEqual($before)
        ->and(SportEloApplication::count())->toBe(3);
});

it('refuses a cycle even when corrupted receipt payloads were rehashed consistently', function () {
    [$a, $b, $c] = transitiveEloFixture();
    $game = transitiveEloGame($a, $b, 2026, 1);
    (new CalculateElo)->execute($game);
    $target = transitiveEloGame($a, $c, 2026, 8);
    $application = SportEloApplication::sole();
    $effect = DB::table('sport_elo_application_effects')->where('application_id', $application->id)->where('entity_id', $a->id)->first();
    // Deliberate privileged damage: normal receipt models prohibit these writes.
    DB::table('sport_elo_application_effects')->where('id', $effect->id)->update(['previous_effect_id' => $effect->id]);
    $snapshot = $application->source_snapshot;
    $snapshot['expected_effects']['team:'.$a->id]['previous_effect_id'] = (int) $effect->id;
    $payload = $application->only(['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint']);
    $payload['source_snapshot'] = $snapshot;
    $payload['played_at'] = $application->played_at->format('Y-m-d H:i:s.u');
    DB::table('sport_elo_applications')->where('id', $application->id)->update(['source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'receipt_hash' => app(CanonicalPayloadHasher::class)->hash($payload)]);
    expect(fn () => (new CalculateElo)->execute($target))->toThrow(EloAdmissionRefused::class, 'dependency_cycle_or_entity_chronology');
    expect(SportEloApplication::count())->toBe(1)->and(EloRating::count())->toBe(2);
});

it('refuses a newly known final in an intervening season previously rolled over without games', function () {
    [$a, $b, $c, $d] = transitiveEloFixture(2024);
    (new CalculateElo)->execute(transitiveEloGame($b, $c, 2024, 1));
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2024, 8));
    (new CalculateElo)->execute(transitiveEloGame($c, $d, 2024, 15));
    app(EloSeasonRollover::class)->execute('nfl', 2025, ['team' => '0.3']);
    app(EloSeasonRollover::class)->execute('nfl', 2026, ['team' => '0.3']);
    (new CalculateElo)->execute(transitiveEloGame($a, $b, 2026, 1));
    transitiveEloGame($b, $c, 2025, 1);
    $target = transitiveEloGame($a, $d, 2026, 8);
    expect(fn () => (new CalculateElo)->execute($target))->toThrow(EloAdmissionRefused::class, 'dependency_unreceipted_ancestor_final');
    expect(SportEloApplication::count())->toBe(4)->and(SportEloEntityHead::where('last_game_id', $target->id)->count())->toBe(0);
});
