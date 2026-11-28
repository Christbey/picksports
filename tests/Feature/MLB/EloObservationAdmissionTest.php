<?php

use App\Actions\MLB\CalculateElo;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Artisan;

function mlbEloAdmissionFixture(): array
{
    $home = Team::factory()->create(['elo_rating' => 1800]);
    $away = Team::factory()->create(['elo_rating' => 1500]);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 3, 'away_score' => 1]);
    foreach ([$home, $away] as $team) {
        $pitcher = Player::factory()->pitcher()->create(['team_id' => $team->id, 'elo_rating' => 1800]);
        PlayerStat::factory()->pitching()->create(['game_id' => $game->id, 'team_id' => $team->id,
            'player_id' => $pitcher->id, 'innings_pitched' => 6]);
    }

    return [$game, $home, $away];
}

it('refuses missing or invalid final scores before team pitcher or history writes', function ($homeScore, $awayScore) {
    [$game] = mlbEloAdmissionFixture();
    $teamsBefore = Team::query()->orderBy('id')->get()->toJson();
    $playersBefore = Player::query()->orderBy('id')->get()->toJson();
    $game->forceFill(['home_score' => $homeScore, 'away_score' => $awayScore]);
    $result = (new CalculateElo)->execute($game);
    expect($result['skipped'])->toBeTrue()
        ->and(Team::query()->orderBy('id')->get()->toJson())->toBe($teamsBefore)
        ->and(Player::query()->orderBy('id')->get()->toJson())->toBe($playersBefore)
        ->and(EloRating::query()->count())->toBe(0)
        ->and(PitcherEloRating::query()->count())->toBe(0);
})->with([[null, 1], [3, null], [null, null], [-1, 1], [1.5, 1], [INF, 1], [NAN, 1], ['invalid', 1]]);

it('retains legitimate explicit zero final score observations', function (int $homeScore, int $awayScore) {
    [$game] = mlbEloAdmissionFixture();
    $game->update(['home_score' => $homeScore, 'away_score' => $awayScore]);
    $result = (new CalculateElo)->execute($game->fresh());
    expect($result['skipped'])->toBeFalse()->and(EloRating::query()->count())->toBe(2)
        ->and(PitcherEloRating::query()->count())->toBe(2);
})->with([[0, 1], [1, 0]]);

it('rejects invalid standalone regression factors before any entity changes', function (array $options) {
    mlbEloAdmissionFixture();
    $teamsBefore = Team::query()->orderBy('id')->get()->toJson();
    $playersBefore = Player::query()->orderBy('id')->get()->toJson();
    $exit = Artisan::call('mlb:apply-offseason-regression', $options);
    expect($exit)->toBe(1)
        ->and(Team::query()->orderBy('id')->get()->toJson())->toBe($teamsBefore)
        ->and(Player::query()->orderBy('id')->get()->toJson())->toBe($playersBefore);
})->with([[['--team-factor' => 1.1]], [['--pitcher-factor' => -0.1]], [['--team-factor' => 'NaN']],
    [['--team-factor' => '1e309']], [['--pitcher-factor' => 'invalid']]]);

it('preserves valid standalone regression factors including zero and one', function (float $teamFactor, float $pitcherFactor) {
    [, $home] = mlbEloAdmissionFixture();
    $this->artisan('mlb:apply-offseason-regression', ['--team-factor' => $teamFactor, '--pitcher-factor' => $pitcherFactor])->assertSuccessful();
    expect((float) $home->fresh()->elo_rating)->toBe((float) round(1800 + $teamFactor * (1500 - 1800)));
    foreach (Player::query()->get() as $player) {
        expect((float) $player->elo_rating)->toBe((float) round(1800 + $pitcherFactor * (1500 - 1800)));
    }
})->with([[0.0, 0.0], [1.0, 1.0], [0.33, 0.40]]);
