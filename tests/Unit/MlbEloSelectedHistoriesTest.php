<?php

use App\Exceptions\EloAdmissionRefused;
use App\Services\Sports\Elo\MlbEloSelectedHistories;

it('preserves exact typed selected history order', function () {
    $requests = [['entity_type' => 'pitcher', 'entity_id' => 8, 'history_id' => 12], ['entity_type' => 'team', 'entity_id' => 3, 'history_id' => 5]];
    expect(MlbEloSelectedHistories::normalize('mlb', $requests))->toBe(['pitcher:8:12' => $requests[0], 'team:3:5' => $requests[1]]);
});

it('admits the exact selected-history and pitcher ceilings without truncation', function () {
    $requests = [];
    for ($id = 1; $id <= 64; $id++) {
        $requests[] = ['entity_type' => 'pitcher', 'entity_id' => $id, 'history_id' => $id];
        $requests[] = ['entity_type' => 'team', 'entity_id' => 1, 'history_id' => $id];
    }
    expect(MlbEloSelectedHistories::normalize('mlb', $requests))->toHaveCount(128);
    $requests[] = ['entity_type' => 'team', 'entity_id' => 2, 'history_id' => 1000];
    expect(fn () => MlbEloSelectedHistories::normalize('mlb', $requests))->toThrow(EloAdmissionRefused::class, 'selected_history_member_budget');
});

it('refuses the sixty-fifth selected pitcher even below the member limit', function () {
    $requests = array_map(fn ($id) => ['entity_type' => 'pitcher', 'entity_id' => $id, 'history_id' => $id], range(1, 65));
    expect(fn () => MlbEloSelectedHistories::normalize('mlb', $requests))->toThrow(EloAdmissionRefused::class, 'selected_history_pitcher_budget');
});

it('refuses duplicate selected members instead of silently changing weights', function () {
    $request = ['entity_type' => 'team', 'entity_id' => 1, 'history_id' => 1];
    expect(fn () => MlbEloSelectedHistories::normalize('mlb', [$request, $request]))->toThrow(EloAdmissionRefused::class, 'selected_history_duplicate');
});

it('refuses unknown source identities rather than guessing a table or coercing an ID', function ($request) {
    expect(fn () => MlbEloSelectedHistories::normalize('mlb', [$request]))->toThrow(EloAdmissionRefused::class, 'selected_history_identity_invalid');
})->with([
    [['entity_type' => 'team', 'entity_id' => '1', 'history_id' => 1]],
    [['entity_type' => 'team', 'entity_id' => true, 'history_id' => 1]],
    [['entity_type' => 'team', 'entity_id' => 1, 'history_id' => 0]],
    [['entity_type' => 'unknown', 'entity_id' => 1, 'history_id' => 1]],
    [['entity_type' => 'team', 'entity_id' => 1, 'history_id' => 1, 'table' => 'foreign_rows']],
]);

it('keeps non MLB selected history unsupported while ordinary empty verification remains compatible', function () {
    expect(MlbEloSelectedHistories::normalize('nfl', []))->toBe([])
        ->and(fn () => MlbEloSelectedHistories::normalize('nfl', [['entity_type' => 'team', 'entity_id' => 1, 'history_id' => 1]]))
        ->toThrow(EloAdmissionRefused::class, 'selected_history_sport_unsupported');
});
