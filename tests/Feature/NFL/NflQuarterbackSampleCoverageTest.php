<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\NflTeamHistory;
use App\Services\NFL\Research\RecommendationEligibility;

it('distinguishes missing current starters from different starters and prior season fallback', function () {
    [$team, $opponent] = Team::factory()->count(2)->create()->all();
    $ids = [];
    foreach ([
        [2025, '2025-12-01', 'Starter'],
        [2026, '2026-09-06', null],
        [2026, '2026-09-13', 'Backup'],
        [2026, '2026-09-20', 'Starter'],
        [2026, '2026-09-27', 'Starter'],
    ] as [$season, $date, $qb]) {
        $ids[] = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id,
            'season' => $season, 'season_type' => '2', 'game_date' => $date, 'status' => 'STATUS_FINAL',
            'home_score' => 21, 'away_score' => 17, 'home_qb_name' => $qb])->id;
    }
    $target = new Game(['home_team_id' => $team->id, 'away_team_id' => $opponent->id,
        'season' => 2026, 'game_date' => '2026-09-27']);
    $make = fn () => new NflTeamHistory($target, fn () => 1500, [$team->id => ['qb_name' => 'Starter']]);
    $context = $make()->totals($team->id)['qb_context'];
    expect($context['sample_game_ids'])->toBe([$ids[0], $ids[3]])
        ->and($context['current_season_available_games'])->toBe(3)
        ->and($context['current_season_unknown_qb_game_ids'])->toBe([$ids[1]])
        ->and($context['current_season_other_qb_games'])->toBe(1)
        ->and($context['current_season_matching_qb_games'])->toBe(1)
        ->and($context['identity_coverage_complete'])->toBeFalse()
        ->and($context['fallback_reason'])->toBe('missing_current_season_starter_identity');
    $eligibility = app(RecommendationEligibility::class)->evaluate([], ['total_environment' => ['home' => ['qb_context' => $context]]]);
    expect($eligibility['data_reasons'])->toContain('incomplete_current_season_quarterback_history');

    // Only a verified repair can admit the missing game; the target-day result remains excluded.
    Game::findOrFail($ids[1])->update(['home_qb_name' => 'Starter']);
    $context = $make()->totals($team->id)['qb_context'];
    expect($context['sample_game_ids'])->toBe([$ids[1], $ids[3]])
        ->and($context['current_season_sample_games'])->toBe(2)
        ->and($context['prior_season_fallback'])->toBeFalse()
        ->and($context['identity_coverage_complete'])->toBeTrue();
    foreach (['rolling', 'opponentAdjusted', 'line'] as $method) {
        expect($make()->$method($team->id)['qb_context']['identity_coverage_complete'])->toBeTrue();
    }
});

it('does not read history without an as of date', function () {
    $target = new Game(['home_team_id' => 1, 'away_team_id' => 2, 'season' => 2026]);
    $history = new NflTeamHistory($target, fn () => 1500, [1 => ['qb_name' => 'Starter']]);
    expect($history->totals(1)['qb_context']['sample_game_ids'])->toBe([]);
});
