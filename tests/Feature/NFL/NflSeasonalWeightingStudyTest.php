<?php

use Audit\NflSeasonalWeightingStudy;

require_once dirname(__DIR__, 3).'/scripts/audits/NflSeasonalWeightingStudy.php';

it('selects weights only on earlier seasons and excludes 2026 outliers', function () {
    $games = [];
    foreach (range(2009, 2026) as $season) {
        foreach ([1, 2] as $week) {
            $games[] = ['id' => count($games) + 1, 'date' => "$season-09-0$week", 'season' => $season,
                'type' => '2', 'week' => $week, 'home' => 1, 'away' => 2, 'neutral' => false,
                'actual' => $week === 1 ? 14.0 : -7.0, 'home_qb_name' => 'Home QB', 'away_qb_name' => 'Away QB'];
        }
    }
    $before = NflSeasonalWeightingStudy::analyze($games, config('nfl.elo'));
    foreach ($games as &$game) {
        if ($game['season'] >= 2021) {
            $game['actual'] = -99;
        }
    }
    unset($game);
    $after = NflSeasonalWeightingStudy::analyze($games, config('nfl.elo'));
    expect($after['selected_on_training'])->toBe($before['selected_on_training'])
        ->and($after['coverage'])->not->toHaveKey(2026)
        ->and($after['promotion_allowed'])->toBeFalse();
    foreach ($before['variants'] as $key => $variant) {
        expect($after['variants'][$key]['training_2010_2020_early'])->toBe($variant['training_2010_2020_early'])
            ->and($variant['holdout_2021_2025_early']['games'])->toBe(10)
            ->and($variant['training_2010_2020_early']['games'])->toBe(22);
    }
});
