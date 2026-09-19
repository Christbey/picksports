<?php

use App\Actions\CFB\CalculateElo;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Services\CFB\Elo\EloRebuildService;
use Illuminate\Support\Facades\DB;

function integrityGame(Team $home, Team $away, string $date, int $season = 2026, int $week = 1): Game
{
    return Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'week' => $week, 'season_type' => 'regular', 'game_date' => $date,
        'game_time' => '12:00:00', 'home_score' => 42, 'away_score' => 7, 'neutral_site' => false,
        'status' => 'STATUS_FINAL']);
}

function integrityTeams(): array
{
    $teams = Team::factory()->count(3)->create(['division' => 'FBS', 'elo_rating' => 1500]);
    foreach ($teams as $team) {
        foreach ([2025, 2026] as $season) {
            TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => $season,
                'subdivision' => 'FBS', 'conference' => 'Test', 'source' => 'test_provider']);
        }
    }

    return $teams->all();
}

it('preserves authoritative ratings across eagerly loaded venue roles and reruns', function () {
    [$a, $b, $c] = integrityTeams();
    $g1 = integrityGame($a, $b, '2026-09-05');
    $g2 = integrityGame($c, $a, '2026-09-12', week: 2);
    $this->artisan('cfb:calculate-elo', ['--season' => 2026])->assertSuccessful();
    $h1 = EloRating::where('game_id', $g1->id)->where('team_id', $a->id)->firstOrFail();
    $h2 = EloRating::where('game_id', $g2->id)->where('team_id', $a->id)->firstOrFail();
    expect((float) $h2->elo_before)->toBe((float) $h1->elo_rating);
    $before = EloRating::get()->toArray();
    $this->artisan('cfb:calculate-elo', ['--season' => 2026])->assertSuccessful();
    expect(EloRating::get()->toArray())->toBe($before);
});

it('rejects partial legacy history instead of silently skipping or doubling it', function () {
    [$a, $b] = integrityTeams();
    $game = integrityGame($a, $b, '2026-09-05');
    EloRating::create(['team_id' => $a->id, 'game_id' => $game->id, 'season' => 2026,
        'week' => 1, 'season_type' => 'regular', 'date' => '2026-09-05', 'elo_rating' => 1520, 'elo_change' => 20]);
    expect(fn () => app(CalculateElo::class)->execute($game))->toThrow(RuntimeException::class, 'incomplete');
    expect(EloRating::count())->toBe(1)->and((float) $a->fresh()->elo_rating)->toBe(1500.0);
});

it('rolls back both ratings and histories when the second history write fails', function () {
    [$a, $b] = integrityTeams();
    $game = integrityGame($a, $b, '2026-09-05');
    EloRating::creating(function ($row) use ($b) {
        if ($row->team_id === $b->id) {
            throw new RuntimeException('simulated failed second write');
        }
    });
    try {
        expect(fn () => app(CalculateElo::class)->execute($game))->toThrow(RuntimeException::class, 'simulated');
        expect(EloRating::count())->toBe(0)->and(DB::table('cfb_elo_season_initializations')->count())->toBe(0)
            ->and((float) $a->fresh()->elo_rating)->toBe(1500.0);
    } finally {
        EloRating::flushEventListeners();
    }
});

it('automatically initializes each season exactly once from the previous ending rating', function () {
    [$a, $b] = integrityTeams();
    app(CalculateElo::class)->execute(integrityGame($a, $b, '2025-09-05', 2025));
    $prior = (float) $a->fresh()->elo_rating;
    $next = integrityGame($a, $b, '2026-09-05');
    app(CalculateElo::class)->execute($next);
    $row = EloRating::where('team_id', $a->id)->where('game_id', $next->id)->firstOrFail();
    expect((float) $row->elo_before)->toBe(round($prior * .7 + 1500 * .3));
    app(CalculateElo::class)->execute($next, false);
    expect(DB::table('cfb_elo_season_initializations')->count())->toBe(4)->and(EloRating::count())->toBe(4);
});

it('rejects missing, negative, and tied final scores without mutations', function ($home, $away) {
    [$a, $b] = integrityTeams();
    $game = integrityGame($a, $b, '2026-09-05');
    $game->update(['home_score' => $home, 'away_score' => $away]);
    expect(fn () => app(CalculateElo::class)->execute($game))->toThrow(RuntimeException::class, 'Invalid final');
    expect(EloRating::count())->toBe(0);
})->with([[null, 7], [-1, 7], [7, 7]]);

it('blocks corrected results and backfills until deterministic replay', function () {
    [$a, $b, $c] = integrityTeams();
    $later = integrityGame($a, $b, '2026-09-12', week: 2);
    app(CalculateElo::class)->execute($later);
    $earlier = integrityGame($c, $a, '2026-09-05');
    expect(fn () => app(CalculateElo::class)->execute($earlier))->toThrow(RuntimeException::class, 'Out-of-order');
    $later->update(['home_score' => 7, 'away_score' => 42]);
    expect(fn () => app(CalculateElo::class)->execute($later))->toThrow(RuntimeException::class, 'corrected');
});

it('replays deterministically and activates without deleting previous history or touching forecasts', function () {
    [$a, $b, $c] = integrityTeams();
    $first = integrityGame($a, $b, '2025-09-05', 2025);
    app(CalculateElo::class)->execute($first);
    integrityGame($c, $a, '2026-09-05');
    $oldIds = EloRating::pluck('id')->all();
    $before = Team::pluck('elo_rating', 'id')->all();
    $service = app(EloRebuildService::class);
    $candidate = $service->build();
    $again = $service->build();
    expect($candidate['input_digest'])->toBe($again['input_digest']);
    $payload = fn ($id) => json_decode(DB::table('cfb_elo_rebuilds')->find($id)->payload, true);
    expect($payload($candidate['id']))->toBe($payload($again['id']))
        ->and(Team::pluck('elo_rating', 'id')->all())->toBe($before)
        ->and(EloRating::count())->toBe(2);
    $service->activate($candidate['id']);
    expect(EloRating::count())->toBe(4)
        ->and(EloRating::withoutGlobalScopes()->whereIn('id', $oldIds)->whereNull('active_slot')->count())->toBe(2)
        ->and(EloRating::whereNull('rebuilt_at')->count())->toBe(0);
    $this->artisan('cfb:calculate-elo')->assertSuccessful();
    expect(EloRating::count())->toBe(4);
});

it('refuses activation after a result changes or new result arrives', function () {
    [$a, $b] = integrityTeams();
    $game = integrityGame($a, $b, '2026-09-05');
    $service = app(EloRebuildService::class);
    $candidate = $service->build();
    $game->update(['away_score' => 10]);
    expect(fn () => $service->activate($candidate['id']))->toThrow(RuntimeException::class, 'changed');
    expect(EloRating::count())->toBe(0);
});

it('disables destructive reset', function () {
    $this->artisan('cfb:calculate-elo', ['--reset' => true])->assertFailed();
});

it('detects a corrected old-season result even during a current-season scheduler run', function () {
    [$a, $b] = integrityTeams();
    $old = integrityGame($a, $b, '2025-09-05', 2025);
    app(CalculateElo::class)->execute($old);
    app(CalculateElo::class)->execute(integrityGame($a, $b, '2026-09-05'));
    $old->update(['status' => 'STATUS_CANCELED']);
    $this->artisan('cfb:calculate-elo', ['--season' => 2026])->assertFailed();
    expect(EloRating::count())->toBe(4);
});

it('requires verified affiliation evidence when building a replay', function () {
    [$a, $b] = integrityTeams();
    integrityGame($a, $b, '2025-09-05', 2025);
    TeamSeasonAffiliation::where('team_id', $a->id)->delete();
    expect(fn () => app(EloRebuildService::class)->build())->toThrow(RuntimeException::class, 'Unverified');
});

it('matches chronological replay when same-day event IDs disagree with kickoff order', function () {
    [$a, $b, $c] = integrityTeams();
    $later = integrityGame($c, $a, '2026-09-05');
    $later->update(['game_time' => '18:00:00']);
    $earlier = integrityGame($a, $b, '2026-09-05');
    $earlier->update(['game_time' => '10:00:00']);
    $candidate = app(EloRebuildService::class)->build();
    $this->artisan('cfb:calculate-elo', ['--season' => 2026])->assertSuccessful();
    $payload = json_decode(DB::table('cfb_elo_rebuilds')->find($candidate['id'])->payload, true);
    foreach ($payload['ratings'] as $teamId => $rating) {
        expect((float) Team::findOrFail($teamId)->elo_rating)->toBe((float) $rating);
    }
    expect((float) EloRating::where('team_id', $a->id)->where('game_id', $later->id)->firstOrFail()->elo_before)
        ->toBe((float) EloRating::where('team_id', $a->id)->where('game_id', $earlier->id)->firstOrFail()->elo_rating);
});

it('rounds a half-point transfer once without inflating combined team ratings', function () {
    [$a, $b] = integrityTeams();
    $game = integrityGame($a, $b, '2026-09-05');
    config(['cfb.elo.home_field_advantage' => 0, 'cfb.elo.base_k_factor' => 39,
        'cfb.elo.recency_multiplier' => 1, 'cfb.elo.mov_coefficient' => 0]);
    $result = app(CalculateElo::class)->execute($game);
    expect($result['home_new_elo'])->toBe(1520)->and($result['away_new_elo'])->toBe(1480)
        ->and($result['home_change'] + $result['away_change'])->toBe(0.0)
        ->and((float) $a->fresh()->elo_rating + (float) $b->fresh()->elo_rating)->toBe(3000.0);
    $candidate = app(EloRebuildService::class)->build();
    expect($candidate['integrity_validated'])->toBeTrue();
});

it('rejects a candidate from an older model before activation', function () {
    [$a, $b] = integrityTeams();
    integrityGame($a, $b, '2026-09-05');
    $service = app(EloRebuildService::class);
    $candidate = $service->build();
    DB::table('cfb_elo_rebuilds')->where('id', $candidate['id'])->update(['model_version' => 'cfb-elo-2.0.0']);
    expect(fn () => $service->activate($candidate['id']))->toThrow(RuntimeException::class, 'another model version');
    expect(EloRating::count())->toBe(0);
});
