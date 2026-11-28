<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\RegularSeasonFuturesResults;
use Carbon\CarbonImmutable;

it('uses shared final states for stored season results while retaining admission gates', function (string $status, bool $included) {
    $this->travelTo(CarbonImmutable::parse('2026-09-03T12:00:00Z'));
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'status' => $status,
        'home_score' => 27, 'away_score' => 20, 'game_date' => '2026-09-01',
        'result_updated_at' => '2026-09-02 12:00:00']);
    $cutoff = CarbonImmutable::parse('2026-10-01T12:00:00Z');
    $results = app(RegularSeasonFuturesResults::class)->records(2026, $cutoff);
    expect($results)->toHaveCount($included ? 2 : 0);
    if ($included) {
        expect($results[$home->id]['wins'])->toBe(1)->and($results[$away->id]['losses'])->toBe(1);
        // A final-state alias must not bypass the existing boxscore evidence gate.
        expect(app(RegularSeasonFuturesResults::class)->records(2026, $cutoff, true))->toBe([]);
        expect(app(RegularSeasonFuturesResults::class)->records(2026, CarbonImmutable::parse('2026-08-01T00:00:00Z')))->toBe([]);
    }
})->with([
    'ordinary final control' => ['STATUS_FINAL', true],
    'full-time final' => ['STATUS_FULL_TIME', true],
    'scheduled score does not settle' => ['STATUS_SCHEDULED', false],
]);
