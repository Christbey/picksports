<?php

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\MLB\Team;
use App\Services\MLB\MlbStartingPitcherProjectionService;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function workloadProjectionFixture(int $players = 3): array
{
    test()->travelTo(CarbonImmutable::parse('2026-07-05 12:00:00 UTC'));
    Http::preventStrayRequests();
    Queue::fake();
    $teams = Team::factory()->count(2)->create();
    $roster = Player::factory()->pitcher()->count($players)->create(['team_id' => $teams[0]->id, 'position' => 'SP']);
    $starts = [];
    foreach (range(0, 2) as $index) {
        $starts[] = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[0]->id,
            'away_team_id' => $teams[1]->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
            'game_date' => '2026-07-0'.($index + 1), 'game_time' => '18:00:00',
            'actual_home_pitcher_espn_id' => $roster[$index]->espn_id]);
    }
    $target = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[0]->id,
        'away_team_id' => $teams[1]->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-06', 'game_time' => '18:00:00']);

    return [$target, $roster, $starts];
}

it('does not let a later batting row mask the latest pitching workload or an explicit zero', function (?int $pitchCount) {
    [$target, $players, $starts] = workloadProjectionFixture();
    if ($pitchCount !== null) {
        PlayerStat::factory()->pitching()->create(['game_id' => $starts[2]->id, 'team_id' => $target->home_team_id,
            'player_id' => $players[2]->id, 'pitch_count' => $pitchCount, 'pitches_thrown' => $pitchCount]);
    }
    PlayerStat::factory()->create(['stat_type' => 'batting', 'game_id' => $starts[2]->id, 'team_id' => $target->home_team_id,
        'player_id' => $players[2]->id, 'pitch_count' => null, 'pitches_thrown' => null]);
    $result = app(MlbStartingPitcherProjectionService::class)->project($target);
    $candidate = collect(data_get($result, 'home.evidence.candidates'))->firstWhere('pitcher_espn_id', $players[2]->espn_id);
    expect($candidate)->not->toBeNull()->and($candidate['last_pitch_count'])->toBe($pitchCount);
})->with([110, 0, null]);

it('admits exactly 64 candidate players without changing the existing candidate output limit', function () {
    [$target] = workloadProjectionFixture(64);
    $result = app(MlbStartingPitcherProjectionService::class)->project($target);
    expect($result['changed'])->toBeTrue()->and(data_get($result, 'home.evidence.candidates'))->toHaveCount(4)
        ->and($target->fresh()->projected_home_pitcher_espn_id)->not->toBeNull();
});

it('refuses the 65th candidate before projection or forecast mutation', function () {
    [$target] = workloadProjectionFixture(65);
    $before = $target->fresh()->getAttributes();
    expect(fn () => app(MlbStartingPitcherProjectionService::class)->project($target))->toThrow(RuntimeException::class, 'candidate player limit');
    expect($target->fresh()->getAttributes())->toBe($before)->and(StartingPitcherForecast::count())->toBe(0);
});

it('returns command failure after a projection write refusal while preserving the prior rows', function () {
    [$target] = workloadProjectionFixture();
    $before = $target->fresh()->getAttributes();
    app()->bind(SyncGamesFromScoreboard::class, fn () => new class extends SyncGamesFromScoreboard
    {
        public function __construct() {}

        public function execute(string $date): int
        {
            return 0;
        }
    });
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saving(function (Game $game) use ($target): void {
            if ($game->id === $target->id && $game->isDirty('pitcher_projection_metadata')) {
                throw new RuntimeException('Synthetic projection write refusal.');
            }
        });
        expect(Artisan::call('mlb:refresh-probable-pitchers', ['--days-ahead' => 1, '--dry-run' => true]))->toBe(1);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($target->fresh()->getAttributes())->toBe($before)->and(StartingPitcherForecast::count())->toBe(0);
});

it('counts candidates across both teams before publishing either side', function () {
    [$target, , $starts] = workloadProjectionFixture(33);
    $away = Player::factory()->pitcher()->count(32)->create(['team_id' => $target->away_team_id, 'position' => 'SP']);
    foreach ($starts as $index => $start) {
        $start->update(['actual_away_pitcher_espn_id' => $away[$index]->espn_id]);
    }
    $before = $target->fresh()->getAttributes();
    expect(fn () => app(MlbStartingPitcherProjectionService::class)->project($target))->toThrow(RuntimeException::class, 'candidate player limit');
    expect($target->fresh()->getAttributes())->toBe($before)->and(StartingPitcherForecast::count())->toBe(0);
});

it('retains latest workload epoch then stat ID ordering for tied observed game starts', function () {
    [$target, $players, $starts] = workloadProjectionFixture();
    $other = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $target->home_team_id,
        'away_team_id' => $target->away_team_id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-07-03', 'game_time' => '18:00:00']);
    foreach ([[$starts[2], 110], [$other, 70]] as [$game, $count]) {
        PlayerStat::factory()->pitching()->create(['game_id' => $game->id, 'team_id' => $target->home_team_id,
            'player_id' => $players[2]->id, 'pitch_count' => $count, 'pitches_thrown' => $count]);
    }
    $result = app(MlbStartingPitcherProjectionService::class)->project($target);
    $candidate = collect(data_get($result, 'home.evidence.candidates'))->firstWhere('pitcher_espn_id', $players[2]->espn_id);
    expect($candidate['last_pitch_count'])->toBe(70);
});

it('retains latest history date then row ID ordering for the selected candidate rating', function () {
    [$target, $players, $starts] = workloadProjectionFixture();
    $other = Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $target->home_team_id,
        'away_team_id' => $target->away_team_id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-07-03', 'game_time' => '21:00:00', 'actual_home_pitcher_espn_id' => $players[2]->espn_id]);
    foreach ([[$starts[2], 1460], [$other, 1540]] as [$game, $rating]) {
        PitcherEloRating::create(['player_id' => $players[2]->id, 'team_id' => $target->home_team_id,
            'game_id' => $game->id, 'season' => 2026, 'date' => '2026-07-03', 'elo_rating' => $rating,
            'elo_change' => 1, 'games_started' => 1]);
    }
    $result = app(MlbStartingPitcherProjectionService::class)->project($target);
    $candidate = collect(data_get($result, 'home.evidence.candidates'))->firstWhere('pitcher_espn_id', $players[2]->espn_id);
    expect($candidate['rating'])->toBe(1540.0);
});

it('resets the total candidate budget for an equivalent retry without duplicate forecasts', function () {
    [$target] = workloadProjectionFixture(64);
    $projector = app(MlbStartingPitcherProjectionService::class);
    $first = $projector->project($target);
    $count = StartingPitcherForecast::count();
    $beforeMetadata = $target->fresh()->pitcher_projection_metadata;
    $again = $projector->project($target->fresh());
    expect(app(CanonicalPayloadHasher::class)->hash($beforeMetadata))
        ->toBe(app(CanonicalPayloadHasher::class)->hash($target->fresh()->pitcher_projection_metadata));
    expect($first['changed'])->toBeTrue()->and($again['changed'])->toBeFalse()
        ->and(StartingPitcherForecast::count())->toBe($count)->and($count)->toBeGreaterThan(0);
});

it('reports actual candidate admission refusal as command failure without changing prior rows', function () {
    [$target] = workloadProjectionFixture(65);
    $before = $target->fresh()->getAttributes();
    app()->bind(SyncGamesFromScoreboard::class, fn () => new class extends SyncGamesFromScoreboard
    {
        public function __construct() {}

        public function execute(string $date): int
        {
            return 0;
        }
    });
    expect(Artisan::call('mlb:refresh-probable-pitchers', ['--days-ahead' => 1, '--dry-run' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('candidate player limit');
    expect($target->fresh()->getAttributes())->toBe($before)->and(StartingPitcherForecast::count())->toBe(0);
});

it('still detects changed candidate list order when comparing persisted projection JSON', function () {
    [$target] = workloadProjectionFixture();
    $projector = app(MlbStartingPitcherProjectionService::class);
    $projector->project($target);
    $metadata = $target->fresh()->pitcher_projection_metadata;
    $firstId = $metadata['home']['candidates'][0]['pitcher_espn_id'];
    $metadata['home']['candidates'] = array_reverse($metadata['home']['candidates']);
    $target->forceFill(['pitcher_projection_metadata' => $metadata])->save();
    $result = $projector->project($target->fresh());
    expect($result['changed'])->toBeTrue()
        ->and(data_get($target->fresh()->pitcher_projection_metadata, 'home.candidates.0.pitcher_espn_id'))->toBe($firstId);
});

it('keeps missing workload distinct from a fabricated zero in projection change detection', function () {
    [$target] = workloadProjectionFixture();
    $projector = app(MlbStartingPitcherProjectionService::class);
    $projector->project($target);
    $metadata = $target->fresh()->pitcher_projection_metadata;
    expect($metadata['home']['candidates'][0]['last_pitch_count'])->toBeNull();
    $metadata['home']['candidates'][0]['last_pitch_count'] = 0;
    $target->forceFill(['pitcher_projection_metadata' => $metadata])->save();
    $result = $projector->project($target->fresh());
    expect($result['changed'])->toBeTrue()
        ->and(data_get($target->fresh()->pitcher_projection_metadata, 'home.candidates.0.last_pitch_count'))->toBeNull();
});
