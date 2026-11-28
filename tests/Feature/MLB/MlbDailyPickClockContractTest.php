<?php

use App\Http\Resources\Api\V2\MlbPickCandidateResource;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Team;
use App\Services\MLB\MlbPeriodModelContextService;
use App\Services\MLB\Picks\MlbPickExplanationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\Support\ObservedMlbGameFixture;

it('exposes only observed candidate kickoff instants and retains unknown legacy clocks', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01T12:00:00Z'));
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-06-02', 'game_time' => '19:00:00', 'status' => 'STATUS_SCHEDULED']);
    $candidate = new PickCandidate(['side' => 'home', 'market_type' => 'moneyline']);
    $candidate->setRelation('game', $game)->setRelation('team', null)->setRelation('player', null);
    $resource = fn () => (new MlbPickCandidateResource($candidate, app(MlbPickExplanationService::class), app(MlbPeriodModelContextService::class), true))->toArray(Request::create('/'));
    expect($resource()['game'])->toHaveKey('starts_at', null);
    ObservedMlbGameFixture::observe($game, CarbonImmutable::parse('2026-06-03T00:00:00Z'), false);
    expect($resource()['game']['starts_at'])->toBe('2026-06-03T00:00:00+00:00');
});
