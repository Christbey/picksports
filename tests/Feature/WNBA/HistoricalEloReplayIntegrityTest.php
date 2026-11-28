<?php

use App\Actions\WNBA\CalculateElo;
use App\Console\Commands\WNBA\BackfillHistoricalDataCommand;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function runHistoricalWnbaIntegrityCommand(array $options = []): int
{
    $command = new BackfillHistoricalDataCommand;
    $command->setLaravel(app());

    return $command->run(new ArrayInput([...['--from-season' => 2025, '--to-season' => 2025, '--stage' => 'metrics'], ...$options], $command->getDefinition()), new BufferedOutput);
}

function historicalWnbaIntegrityGame(array $attributes = []): Game
{
    return Game::factory()->create([...['season' => 2025, 'season_type' => '2', 'game_date' => '2025-06-01', 'game_time' => '12:00:00',
        'status' => 'STATUS_FINAL', 'home_team_id' => Team::factory()->create(['elo_rating' => 1500])->id,
        'away_team_id' => Team::factory()->create(['elo_rating' => 1500])->id, 'home_score' => 90, 'away_score' => 80], ...$attributes]);
}

it('replays tied game math consistently without changing current team ratings', function (int $homeScore, int $awayScore) {
    $game = historicalWnbaIntegrityGame(['home_score' => $homeScore, 'away_score' => $awayScore]);
    (new CalculateElo)->execute($game->fresh());
    $expected = EloRating::query()->orderBy('team_id')->get(['team_id', 'elo_rating', 'elo_change'])->toJson();
    EloRating::query()->delete();
    $game->homeTeam->update(['elo_rating' => 1750]);
    $game->awayTeam->update(['elo_rating' => 1250]);
    $teamsBefore = Team::query()->orderBy('id')->get()->toJson();
    Artisan::shouldReceive('call')->once()->with('wnba:calculate-team-metrics', Mockery::type('array'), Mockery::any())->andReturn(0);
    expect(runHistoricalWnbaIntegrityCommand())->toBe(0);
    expect(EloRating::query()->orderBy('team_id')->get(['team_id', 'elo_rating', 'elo_change'])->toJson())->toBe($expected)
        ->and(Team::query()->orderBy('id')->get()->toJson())->toBe($teamsBefore);
})->with([[80, 80]]);

it('retains prior scoped history after a write failure during historical replay', function () {
    $game = historicalWnbaIntegrityGame();
    EloRating::create(['team_id' => $game->home_team_id, 'game_id' => $game->id, 'season' => 2025, 'week' => 1, 'date' => $game->game_date, 'elo_rating' => 1700, 'elo_change' => 10]);
    $before = EloRating::query()->orderBy('id')->get()->toJson();
    $quoted = DB::connection()->getQueryGrammar()->wrapTable('wnba_elo_ratings');
    DB::listen(function (QueryExecuted $query) use ($quoted): void {
        if (str_starts_with(strtolower($query->sql), 'insert into '.strtolower($quoted))) {
            throw new RuntimeException('Synthetic replay write failure');
        }
    });
    Artisan::shouldReceive('call')->never();
    $exit = null;
    try {
        $exit = runHistoricalWnbaIntegrityCommand();
    } catch (RuntimeException) {
        // Preserve the original failure window for the before-fix source proof.
    }
    expect(EloRating::query()->orderBy('id')->get()->toJson())->toBe($before)->and($exit)->toBe(1);
});

it('rejects incomplete final game observations before replacing existing history', function () {
    $game = historicalWnbaIntegrityGame(['home_score' => null]);
    EloRating::create(['team_id' => $game->home_team_id, 'game_id' => $game->id, 'season' => 2025, 'week' => 1, 'date' => $game->game_date, 'elo_rating' => 1700, 'elo_change' => 10]);
    $before = EloRating::query()->orderBy('id')->get()->toJson();
    Artisan::shouldReceive('call')->never();
    expect(runHistoricalWnbaIntegrityCommand())->toBe(1);
    expect(EloRating::query()->orderBy('id')->get()->toJson())->toBe($before);
});

it('rejects regrade and invalid season bounds before dispatching any child stage', function (array $options) {
    Artisan::shouldReceive('call')->never();
    expect(runHistoricalWnbaIntegrityCommand([...['--stage' => 'full'], ...$options]))->toBe(1);
})->with([
    [['--regrade' => true]], [['--from-season' => 'bad']], [['--from-season' => 2000, '--to-season' => 2025]],
]);

it('rejects an empty scoped replay without clearing existing historical ratings', function () {
    $game = historicalWnbaIntegrityGame(['status' => 'STATUS_SCHEDULED']);
    EloRating::create(['team_id' => $game->home_team_id, 'game_id' => $game->id, 'season' => 2025, 'week' => 1, 'date' => $game->game_date, 'elo_rating' => 1700, 'elo_change' => 10]);
    $before = EloRating::query()->orderBy('id')->get()->toJson();
    Artisan::shouldReceive('call')->never();
    expect(runHistoricalWnbaIntegrityCommand())->toBe(1);
    expect(EloRating::query()->orderBy('id')->get()->toJson())->toBe($before);
});

it('preserves unrelated season history and admits explicitly observed zero scores', function () {
    $game = historicalWnbaIntegrityGame(['home_score' => 0, 'away_score' => 0]);
    $older = historicalWnbaIntegrityGame(['season' => 2024, 'game_date' => '2024-06-01']);
    $prior = EloRating::create(['team_id' => $older->home_team_id, 'game_id' => $older->id, 'season' => 2024, 'week' => 1, 'date' => $older->game_date, 'elo_rating' => 1700, 'elo_change' => 10])->fresh()->getAttributes();
    Artisan::shouldReceive('call')->once()->with('wnba:calculate-team-metrics', Mockery::type('array'), Mockery::any())->andReturn(0);
    expect(runHistoricalWnbaIntegrityCommand())->toBe(0);
    expect(EloRating::query()->where('game_id', $game->id)->count())->toBe(2)
        ->and(EloRating::query()->where('id', $prior['id'])->firstOrFail()->getAttributes())->toBe($prior);
});

it('refuses changed final source observations between staging and locked publication', function () {
    $game = historicalWnbaIntegrityGame();
    EloRating::create(['team_id' => $game->home_team_id, 'game_id' => $game->id, 'season' => 2025, 'week' => 1, 'date' => $game->game_date, 'elo_rating' => 1700, 'elo_change' => 10]);
    $before = EloRating::query()->orderBy('id')->get()->toJson();
    $changed = false;
    Event::listen('eloquent.retrieved: '.Game::class, function (Game $loaded) use (&$changed): void {
        if (! $changed) {
            $changed = true;
            DB::table('wnba_games')->where('id', $loaded->id)->update(['home_score' => 95]);
        }
    });
    Artisan::shouldReceive('call')->never();
    expect(runHistoricalWnbaIntegrityCommand())->toBe(1);
    expect($changed)->toBeTrue()->and(EloRating::query()->orderBy('id')->get()->toJson())->toBe($before);
});

it('uses the explicit neutral flag in pure shared Elo math without persistence', function () {
    $game = new Game;
    $game->forceFill(['status' => 'STATUS_FINAL', 'season_type' => '2', 'home_score' => 90, 'away_score' => 80, 'neutral_site' => true]);
    $calculator = new CalculateElo;
    $neutral = $calculator->previewChanges($game, 1500, 1500);
    $game->neutral_site = false;
    $home = $calculator->previewChanges($game, 1500, 1500);
    expect($neutral['home_change'])->toBeGreaterThan($home['home_change'])
        ->and($neutral['home_change'])->toBe(-$neutral['away_change']);
});
