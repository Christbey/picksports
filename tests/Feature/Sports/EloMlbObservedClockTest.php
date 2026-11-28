<?php

use App\Exceptions\EloAdmissionRefused;
use App\Models\MLB\Game;
use App\Services\Sports\Elo\EloEligibilityPolicy;
use App\Services\Sports\Elo\EloSourceContext;
use App\Support\MLB\MlbGameStart;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function observedEloMlbSource(): Game
{
    $game = new Game;
    $attributes = ['id' => 41, 'espn_event_id' => 'synthetic-elo-source', 'season' => 2026, 'season_type' => 2,
        'game_date' => '2026-06-01', 'game_time' => '22:00:00', 'home_team_id' => 1, 'away_team_id' => 2,
        'home_score' => 0, 'away_score' => 1, 'status' => 'STATUS_FINAL'];
    $game->forceFill(MlbGameStart::prepare($game, $attributes + MlbGameStart::observed('2026-06-02T03:00:00Z', [])))->syncOriginal();

    return $game;
}

it('orders guarded MLB finals by their observed instant rather than legacy wall fields', function () {
    $game = observedEloMlbSource();
    expect(app(EloSourceContext::class)->gameKey($game))->toBe(['2026-06-02 03:00:00.000000', 41]);
    $source = app(EloSourceContext::class)->source('mlb', $game, []);
    expect($source['played_at_utc'])->toBe('2026-06-02 03:00:00.000000')
        ->and(substr($source['game']['game_date'], 0, 10))->toBe('2026-06-01');
});

it('retains all observed MLB clock identity fields in the immutable source contract', function () {
    $game = observedEloMlbSource();
    $source = app(EloSourceContext::class)->source('mlb', $game, []);
    foreach (MlbGameStart::FIELDS as $field) {
        expect($source['game'])->toHaveKey($field)
            ->and($source['game'][$field])->toBe($game->getRawOriginal($field));
    }
    expect(app(EloEligibilityPolicy::class)->specification('mlb')['source_clock']['version'])->toBe('mlb-observed-start-v1');
});

it('refuses an unverified legacy MLB clock instead of certifying its raw wall timestamp', function () {
    $game = observedEloMlbSource();
    foreach (MlbGameStart::FIELDS as $field) {
        $game->setAttribute($field, null);
    }
    $game->syncOriginal();
    expect(fn () => app(EloSourceContext::class)->gameKey($game))->toThrow(EloAdmissionRefused::class);
});

it('refuses changed MLB observation identity even when legacy date and scores are unchanged', function () {
    $game = observedEloMlbSource();
    $game->setAttribute('start_observation_id', '00000000-0000-4000-8000-000000000000')->syncOriginal();
    expect(fn () => app(EloSourceContext::class)->source('mlb', $game, []))->toThrow(EloAdmissionRefused::class);
});
