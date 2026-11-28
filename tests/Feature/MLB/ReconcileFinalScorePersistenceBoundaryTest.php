<?php

use App\Actions\ESPN\MLB\SyncTeamStats;
use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Exceptions\MlbScoreReconciliationPersistenceException;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Notification::fake();
});

afterEach(function () {
    Http::assertNothingSent();
    Notification::assertNothingSent();
    $this->travelBack();
});

function scorePersistenceGame(?int $home = null, ?int $away = null): Game
{
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create([
        'home_team_id' => $teams[0]->id,
        'away_team_id' => $teams[1]->id,
        'season' => 2026,
        'season_type' => '2',
        'game_date' => '2026-10-27',
        'status' => 'STATUS_FINAL',
        'home_score' => $home,
        'away_score' => $away,
    ]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[0]->id, 'team_type' => 'home', 'runs' => 0]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[1]->id, 'team_type' => 'away', 'runs' => 2]);

    return $game;
}

function scorePersistenceRows(array $extraTables = []): array
{
    $state = [];
    foreach (array_merge(['mlb_games', 'mlb_team_stats', 'sport_events', 'predictions'], $extraTables) as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    return $state;
}

function scorePersistenceCommand(array $options = []): array
{
    try {
        $exit = Artisan::call('mlb:reconcile-final-scores', ['--season' => '2026', ...$options]);

        return ['exit' => $exit, 'output' => Artisan::output(), 'exception' => null];
    } catch (Throwable $error) {
        return ['exit' => null, 'output' => Artisan::output(), 'exception' => $error::class, 'message' => $error->getMessage()];
    }
}

function scorePersistenceBeforeState(string $case, array $before, array $result, array $after): array
{
    $record = compact('case', 'before', 'result', 'after');

    return $record;
}

it('refuses to acknowledge a cancelled final-score save through the reconciliation command', function () {
    $game = scorePersistenceGame();
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saving(function (Game $saving) use ($game): ?bool {
            return $saving->id === $game->id && $saving->isDirty(['home_score', 'away_score']) ? false : null;
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('cancelled_save', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('refuses to acknowledge a rewritten planned score through the reconciliation command', function () {
    $game = scorePersistenceGame();
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saving(function (Game $saving) use ($game): void {
            if ($saving->id === $game->id && $saving->isDirty(['home_score', 'away_score'])) {
                $saving->home_score = 99;
            }
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('saving_rewrite', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('refuses to acknowledge a saved-hook persisted score rewrite through the reconciliation command', function () {
    $game = scorePersistenceGame();
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saved(function (Game $saved) use ($game): void {
            if ($saved->id === $game->id) {
                DB::table('mlb_games')->where('id', $game->id)->update(['home_score' => 99]);
            }
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('saved_row_rewrite', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('persists genuine zero scores and preserves all rows on a public missing-only replay', function () {
    $game = scorePersistenceGame();
    $result = scorePersistenceCommand(['--missing-only' => true]);
    expect($result['exception'])->toBeNull()->and($result['exit'])->toBe(0)
        ->and($game->fresh()->home_score)->toBe(0)->and($game->fresh()->away_score)->toBe(2);
    $after = scorePersistenceRows();
    $replay = scorePersistenceCommand(['--missing-only' => true]);
    expect($replay['exception'])->toBeNull()->and($replay['exit'])->toBe(0)
        ->and(scorePersistenceRows())->toBe($after);
});

it('preserves conflict and dry-run policies while supporting an explicit forced score reconciliation', function () {
    $game = scorePersistenceGame(home: 5, away: 2);
    $before = scorePersistenceRows();
    $conflict = scorePersistenceCommand();
    expect($conflict['exception'])->toBeNull()->and($conflict['exit'])->toBe(0)
        ->and(scorePersistenceRows())->toBe($before);
    $dryRun = scorePersistenceCommand(['--force' => true, '--dry-run' => true]);
    expect($dryRun['exception'])->toBeNull()->and($dryRun['exit'])->toBe(0)
        ->and(scorePersistenceRows())->toBe($before);
    $forced = scorePersistenceCommand(['--force' => true]);
    expect($forced['exception'])->toBeNull()->and($forced['exit'])->toBe(0)
        ->and($game->fresh()->home_score)->toBe(0)->and($game->fresh()->away_score)->toBe(2);
});

function scorePersistenceActionResult(callable $operation): array
{
    try {
        return ['result' => $operation(), 'exception' => null];
    } catch (MlbScoreReconciliationPersistenceException $error) {
        return ['result' => null, 'exception' => $error::class];
    }
}

it('pins the original game when a saved callback retargets model memory to a different correct row', function () {
    $game = scorePersistenceGame();
    $other = scorePersistenceGame(home: 0, away: 2);
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saved(function (Game $saved) use ($game, $other): void {
            if ($saved->id === $game->id) {
                DB::table('mlb_games')->where('id', $game->id)->update(['home_score' => 99]);
                $saved->id = $other->id;
            }
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('saved_model_id_retarget', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('refuses a changed game owner header after the score save', function () {
    $game = scorePersistenceGame();
    $foreign = Team::factory()->create();
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saved(function (Game $saved) use ($game, $foreign): void {
            if ($saved->id === $game->id) {
                DB::table('mlb_games')->where('id', $game->id)->update(['home_team_id' => $foreign->id]);
            }
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('saved_owner_header_rewrite', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('rechecks team stat run sources on the owning write PDO after saving the game', function () {
    $game = scorePersistenceGame();
    $pdo = $game->getConnection()->getPdo();
    $before = scorePersistenceRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saved(function (Game $saved) use ($game, $pdo): void {
            if ($saved->id === $game->id) {
                $statement = $pdo->prepare('UPDATE mlb_team_stats SET runs = ? WHERE game_id = ? AND team_id = ? AND team_type = ?');
                $statement->execute([99, $game->id, $game->home_team_id, 'home']);
            }
        });
        $result = scorePersistenceCommand(['--missing-only' => true]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('same_write_pdo_source_rewrite', $before, $result, $after);
    expect($result['exception'] !== null || $result['exit'] !== 0, json_encode($record))->toBeTrue()
        ->and($result['output'])->not->toContain('Games updated')
        ->and($after)->toBe($before);
});

it('refuses a dirty caller status instead of promoting a scheduled game through score reconciliation', function () {
    $game = scorePersistenceGame();
    $game->update(['status' => 'STATUS_SCHEDULED']);
    $game->status = 'STATUS_FINAL';
    $before = scorePersistenceRows();
    $result = scorePersistenceActionResult(fn () => app(ReconcileGameScoreFromTeamStats::class)->execute($game));
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('dirty_input_status', $before, $result, $after);
    expect($result['exception'], json_encode($record))->toBe(MlbScoreReconciliationPersistenceException::class)
        ->and($after)->toBe($before);
});

it('uses the current persisted game header rather than a stale clean final model', function () {
    $game = scorePersistenceGame();
    DB::table('mlb_games')->where('id', $game->id)->update(['status' => 'STATUS_SCHEDULED']);
    $before = scorePersistenceRows();
    $result = scorePersistenceActionResult(fn () => app(ReconcileGameScoreFromTeamStats::class)->execute($game));
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('stale_clean_input_status', $before, $result, $after);
    expect($result['exception'], json_encode($record))->toBe(MlbScoreReconciliationPersistenceException::class)
        ->and($after)->toBe($before);
});

it('refuses a same-shaped temporary store transplanted onto a persisted game model', function () {
    $game = scorePersistenceGame();
    DB::statement('CREATE TEMPORARY TABLE mlb_reconcile_alternate_games AS SELECT * FROM mlb_games WHERE 1 = 0');
    try {
        DB::table('mlb_reconcile_alternate_games')->insert($game->getRawOriginal());
        $game->setTable('mlb_reconcile_alternate_games');
        $before = scorePersistenceRows(['mlb_reconcile_alternate_games']);
        $result = scorePersistenceActionResult(fn () => app(ReconcileGameScoreFromTeamStats::class)->execute($game));
        $after = scorePersistenceRows(['mlb_reconcile_alternate_games']);
        $record = scorePersistenceBeforeState('transplanted_temporary_table', $before, $result, $after);
        expect($result['exception'], json_encode($record))->toBe(MlbScoreReconciliationPersistenceException::class)
            ->and($after)->toBe($before);
    } finally {
        DB::statement(DB::getDriverName() === 'mysql'
            ? 'DROP TEMPORARY TABLE mlb_reconcile_alternate_games'
            : 'DROP TABLE mlb_reconcile_alternate_games');
    }
});

it('preserves genuine zero and idempotence when the same public game object is reused', function () {
    $game = scorePersistenceGame();
    $first = app(ReconcileGameScoreFromTeamStats::class)->execute($game);
    expect($first['status'])->toBe('updated')
        ->and($game->home_score)->toBe(0)->and($game->away_score)->toBe(2);
    $beforeReplay = scorePersistenceRows();
    $second = app(ReconcileGameScoreFromTeamStats::class)->execute($game);
    expect($second['status'])->toBe('unchanged')->and(scorePersistenceRows())->toBe($beforeReplay);
});

function scorePersistenceProviderPayload(Game $game): array
{
    return ['boxscore' => ['teams' => [
        ['team' => ['id' => $game->homeTeam->espn_id], 'statistics' => [['name' => 'batting', 'stats' => [['name' => 'runs', 'displayValue' => '4']]]]],
        ['team' => ['id' => $game->awayTeam->espn_id], 'statistics' => [['name' => 'batting', 'stats' => [['name' => 'runs', 'displayValue' => '2']]]]],
    ]]];
}

function scorePersistenceProviderGame(): Game
{
    $game = scorePersistenceGame();
    TeamStat::query()->where('game_id', $game->id)->delete();
    TeamStat::query()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'team_type' => 'home', 'runs' => 0]);
    TeamStat::query()->create(['game_id' => $game->id, 'team_id' => $game->away_team_id, 'team_type' => 'away', 'runs' => 2]);

    return $game;
}

it('does not swallow a refused score save as invalid input while replacing genuine team stats', function () {
    $game = scorePersistenceProviderGame();
    $payload = scorePersistenceProviderPayload($game);
    $before = scorePersistenceRows();
    $reached = false;
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    try {
        Game::saving(function (Game $saving) use ($game, &$reached): ?bool {
            if ($saving->id === $game->id && $saving->isDirty(['home_score', 'away_score'])) {
                $reached = true;

                return false;
            }

            return null;
        });
        $result = scorePersistenceActionResult(fn () => app(SyncTeamStats::class)->execute($payload, $game));
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = scorePersistenceRows();
    $record = scorePersistenceBeforeState('producer_cancelled_save', $before, [...$result, 'saving_reached' => $reached], $after);
    if (! $reached) {
        throw new RuntimeException('Synthetic producer did not reach the final-score saving boundary: '.json_encode($record));
    }
    expect($result['exception'], json_encode($record))->toBe(MlbScoreReconciliationPersistenceException::class)
        ->and($after)->toBe($before);
});

it('preserves supported team-stat replacement and genuine score persistence through the real producer', function () {
    $game = scorePersistenceProviderGame();
    $result = app(SyncTeamStats::class)->execute(scorePersistenceProviderPayload($game), $game);
    expect($result)->toBe(2)->and($game->fresh()->home_score)->toBe(4)->and($game->fresh()->away_score)->toBe(2)
        ->and(TeamStat::query()->where('game_id', $game->id)->where('team_type', 'home')->value('runs'))->toBe(4);
});
