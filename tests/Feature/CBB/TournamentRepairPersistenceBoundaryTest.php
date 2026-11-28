<?php

use App\Actions\CBB\RepairTournamentStructure;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\SportEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 2, 1)->setTime(12, 0));
    Notification::fake();
    Http::preventStrayRequests();
});

afterEach(fn () => $this->travelBack());

function tournamentPersistenceFixture(): array
{
    $michigan = Team::factory()->create(['school' => 'Michigan', 'mascot' => 'Wolverines', 'abbreviation' => 'MICH']);
    $howard = Team::factory()->create(['school' => 'Howard', 'mascot' => 'Bison', 'abbreviation' => 'HOW']);
    $umbc = Team::factory()->create(['school' => 'UMBC', 'mascot' => 'Retrievers', 'abbreviation' => 'UMBC']);
    $common = ['season' => 2026, 'week' => 1, 'season_type' => (int) config('cbb.season.types.postseason'),
        'game_date' => '2026-03-18', 'game_time' => '23:00:00', 'is_ncaa_tournament' => true,
        'tournament_region' => 'Midwest', 'home_team_id' => $howard->id, 'away_team_id' => $umbc->id,
        'status' => config('cbb.statuses.scheduled')];
    $firstFour = Game::factory()->create([...$common, 'espn_event_id' => 'synthetic-first-four-midwest-16',
        'tournament_round' => 'first_four', 'home_seed' => 16, 'away_seed' => 16, 'play_in_target_seed' => 16]);
    $real = Game::factory()->create([...$common, 'espn_event_id' => 'synthetic-midwest-8-9',
        'tournament_round' => 'round_of_64', 'home_seed' => 8, 'away_seed' => 9]);
    $history = Game::factory()->create([...$common, 'espn_event_id' => 'synthetic-retained-history', 'season' => 2025,
        'status' => config('cbb.statuses.final'), 'home_score' => 67, 'away_score' => 61]);

    return [$michigan, $firstFour, $real, $history];
}

function tournamentRawRows(): array
{
    return Game::query()->getConnection()->table('cbb_games')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('repairs real missing tournament slots and retains exact existing rows on an unchanged rerun', function () {
    [$michigan, $firstFour, $real, $history] = tournamentPersistenceFixture();
    $retained = collect([$firstFour, $real, $history])->mapWithKeys(fn ($game) => [$game->id => $game->fresh()->getRawOriginal()])->all();
    $action = app(RepairTournamentStructure::class);
    expect($action->execute(2026))->toBe(31);
    $rows = tournamentRawRows();
    expect(Game::where('season', 2026)->where('tournament_round', 'round_of_64')->count())->toBe(32);
    $slot = Game::where('espn_event_id', 'placeholder:2026:midwest:1-16')->sole();
    expect($slot->home_team_id)->toBe($michigan->id)->and($slot->away_team_id)->toBeNull()
        ->and($slot->away_team_display_name)->toBe('Winner of UMBC Retrievers / Howard Bison');
    $this->travelTo(now()->addHour());
    expect($action->execute(2026))->toBe(31)->and(tournamentRawRows())->toBe($rows);
    foreach ($retained as $id => $raw) {
        expect(Game::findOrFail($id)->getRawOriginal())->toBe($raw);
    }
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('refuses a cancelled rewritten or missing slot without partial tournament writes', function (string $intervention) {
    tournamentPersistenceFixture();
    $before = tournamentRawRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $seen = [];
    $reached = false;
    $target = $intervention === 'creating_first' ? 1 : 16;
    $event = $intervention === 'saving_middle' ? 'saving' : ($intervention === 'delete_after_create' ? 'created' : 'creating');
    Game::{$event}(function (Game $game) use (&$seen, &$reached, $target, $intervention) {
        $key = $game->espn_event_id;
        if (! str_starts_with($key, 'placeholder:2026:')) {
            return null;
        }
        $seen[$key] ??= count($seen) + 1;
        if ($seen[$key] !== $target) {
            return null;
        }
        $reached = true;
        if ($intervention === 'rewrite_middle') {
            $game->tournament_region = 'SyntheticWrongRegion';
        } elseif ($intervention === 'delete_after_create') {
            $game->getConnection()->table('cbb_games')->where('id', $game->id)->delete();
        } else {
            return false;
        }

        return null;
    });
    $error = null;
    $reported = null;
    try {
        $reported = app(RepairTournamentStructure::class)->execute(2026);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    $after = tournamentRawRows();
    fwrite(STDERR, json_encode(['intervention' => $intervention, 'reached' => $reached, 'reported' => $reported,
        'before_rows' => count($before), 'after_rows' => count($after), 'raw_preserved' => $before === $after]).PHP_EOL);
    expect($reached)->toBeTrue()->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and($reported)->toBeNull()->and($after)->toBe($before);
    Notification::assertNothingSent();
    Http::assertNothingSent();
})->with(['creating_first', 'creating_middle', 'saving_middle', 'rewrite_middle', 'delete_after_create']);

it('refuses a cancelled obsolete placeholder deletion and retains the original real slot history', function () {
    [, , $real] = tournamentPersistenceFixture();
    $attributes = $real->getAttributes();
    unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
    $attributes['espn_event_id'] = 'placeholder:2026:midwest:8-9';
    Game::query()->create($attributes);
    $before = tournamentRawRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $reached = false;
    Game::deleting(function (Game $game) use (&$reached) {
        if ($game->espn_event_id === 'placeholder:2026:midwest:8-9') {
            $reached = true;

            return false;
        }

        return null;
    });
    $error = null;
    try {
        app(RepairTournamentStructure::class)->execute(2026);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue()->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and(tournamentRawRows())->toBe($before);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('checks the complete intended slot set after the final created observer', function () {
    tournamentPersistenceFixture();
    $before = tournamentRawRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $reached = false;
    Game::created(function (Game $game) use (&$reached): void {
        if ($game->espn_event_id === 'placeholder:2026:midwest:2-15') {
            $reached = true;
            $game->getConnection()->table('cbb_games')->where('espn_event_id', 'placeholder:2026:east:1-16')->delete();
        }
    });
    $error = null;
    try {
        app(RepairTournamentStructure::class)->execute(2026);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue()->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and(tournamentRawRows())->toBe($before);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('updates an existing placeholder without replacing its primary key or unpublished event link', function () {
    tournamentPersistenceFixture();
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2026]);
    $placeholder = Game::factory()->create(['espn_event_id' => 'placeholder:2026:east:1-16', 'season' => 2026,
        'season_type' => (int) config('cbb.season.types.postseason'), 'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64', 'tournament_region' => 'East', 'home_seed' => 1, 'away_seed' => 16,
        'sport_event_id' => $event->id]);
    $beforeEvent = $event->fresh()->getRawOriginal();
    expect(app(RepairTournamentStructure::class)->execute(2026))->toBe(31);
    $after = Game::where('espn_event_id', 'placeholder:2026:east:1-16')->sole();
    expect($after->id)->toBe($placeholder->id)->and($after->sport_event_id)->toBe($event->id)
        ->and($event->fresh()->getRawOriginal())->toBe($beforeEvent);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('refuses obsolete placeholder removal with retained prediction or canonical identity references', function (string $kind) {
    [, , $real] = tournamentPersistenceFixture();
    $attributes = $real->getAttributes();
    unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
    $attributes['espn_event_id'] = 'placeholder:2026:midwest:8-9';
    $placeholder = Game::query()->create($attributes);
    $prediction = null;
    if ($kind === 'prediction') {
        // Retained legacy row, not a published/approved canonical forecast or training proof.
        $prediction = Prediction::factory()->create(['game_id' => $placeholder->id]);
        $prediction = $prediction->fresh();
    } else {
        $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2026]);
        $placeholder->update(['sport_event_id' => $event->id]);
    }
    $before = tournamentRawRows();
    $predictionRaw = $prediction?->getRawOriginal();
    $error = null;
    try {
        app(RepairTournamentStructure::class)->execute(2026);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    expect($error)->toBeInstanceOf(RuntimeException::class)->and(tournamentRawRows())->toBe($before);
    if ($prediction !== null) {
        expect($prediction->fresh()?->getRawOriginal())->toBe($predictionRaw);
    }
    Notification::assertNothingSent();
    Http::assertNothingSent();
})->with(['prediction', 'unpublished_event_link']);

it('refuses a saving observer replacing an existing placeholder primary identity', function () {
    tournamentPersistenceFixture();
    $placeholder = Game::factory()->create(['espn_event_id' => 'placeholder:2026:east:1-16', 'season' => 2026,
        'season_type' => (int) config('cbb.season.types.postseason'), 'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64', 'tournament_region' => 'East', 'home_seed' => 1, 'away_seed' => 16]);
    $before = tournamentRawRows();
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $reached = false;
    Game::saving(function (Game $game) use (&$reached, $placeholder): void {
        if ($game->exists && $game->getRawOriginal('id') === $placeholder->id) {
            $reached = true;
            $game->id = 900000;
        }
    });
    $error = null;
    try {
        app(RepairTournamentStructure::class)->execute(2026);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue()->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and(tournamentRawRows())->toBe($before);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});
