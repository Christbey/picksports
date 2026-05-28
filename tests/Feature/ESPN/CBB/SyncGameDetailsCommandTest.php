<?php

use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

uses()->group('espn', 'cbb');

function createCbbGameWithCompleteDetail(array $gameOverrides = [], array $lastPlayOverrides = []): Game
{
    $homeTeam = Team::factory()->create(['espn_id' => fake()->unique()->numberBetween(1000, 9999)]);
    $awayTeam = Team::factory()->create(['espn_id' => fake()->unique()->numberBetween(1000, 9999)]);
    $player = Player::create([
        'team_id' => $homeTeam->id,
        'espn_id' => fake()->unique()->numberBetween(100000, 999999),
        'first_name' => 'Test',
        'last_name' => 'Guard',
        'full_name' => 'Test Guard',
    ]);

    $game = Game::factory()->create([
        'espn_event_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 79,
        'away_score' => 73,
        'game_date' => now()->subDay()->toDateString(),
        ...$gameOverrides,
    ]);

    PlayerStat::create([
        'player_id' => $player->id,
        'game_id' => $game->id,
        'team_id' => $homeTeam->id,
        'minutes_played' => '30',
        'points' => 12,
    ]);

    TeamStat::factory()->create([
        'team_id' => $homeTeam->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'points' => $game->home_score ?? 79,
    ]);

    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $homeTeam->id,
        'sequence_number' => 1,
        'period' => 1,
        'home_score' => 2,
        'away_score' => 0,
    ]);

    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $awayTeam->id,
        'sequence_number' => 2,
        'period' => 2,
        'home_score' => $game->home_score ?? 79,
        'away_score' => $game->away_score ?? 73,
        ...$lastPlayOverrides,
    ]);

    return $game;
}

it('queues CBB game details for final games with missing scores even when stats already exist', function () {
    Queue::fake();

    $game = createCbbGameWithCompleteDetail([
        'espn_event_id' => '401800001',
        'home_score' => null,
        'away_score' => null,
    ]);

    artisan('espn:sync-cbb-game-details')->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, fn (FetchGameDetails $job) => $job->uniqueId() === $game->espn_event_id);
});

it('queues CBB game details when the last stored play does not match the final score', function () {
    Queue::fake();

    $game = createCbbGameWithCompleteDetail(
        ['espn_event_id' => '401800002', 'home_score' => 88, 'away_score' => 81],
        ['home_score' => 12, 'away_score' => 10]
    );

    artisan('espn:sync-cbb-game-details')->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, fn (FetchGameDetails $job) => $job->uniqueId() === $game->espn_event_id);
});

it('does not queue CBB game details when scores, stats, and final plays are complete', function () {
    Queue::fake();

    createCbbGameWithCompleteDetail(['espn_event_id' => '401800003']);

    artisan('espn:sync-cbb-game-details')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('can limit CBB sweep dispatches to the newest stale games', function () {
    Queue::fake();

    createCbbGameWithCompleteDetail(
        [
            'espn_event_id' => '401800004',
            'game_date' => now()->subDays(4)->toDateString(),
            'home_score' => 80,
            'away_score' => 72,
        ],
        ['home_score' => 10, 'away_score' => 8]
    );
    $newerGame = createCbbGameWithCompleteDetail(
        [
            'espn_event_id' => '401800005',
            'game_date' => now()->subDay()->toDateString(),
            'home_score' => 77,
            'away_score' => 70,
        ],
        ['home_score' => 12, 'away_score' => 6]
    );

    artisan('espn:sync-cbb-game-details --latest --limit=1')->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(FetchGameDetails::class, fn (FetchGameDetails $job) => $job->uniqueId() === $newerGame->espn_event_id);
});
