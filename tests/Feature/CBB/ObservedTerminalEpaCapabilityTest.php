<?php

use App\Actions\CBB\CalculateGamePlayEpa;
use App\Actions\CBB\RefreshTeamPlayEpa;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
use App\Models\EpaStateBaseline;
use App\Models\ModelRun;
use App\Services\CBB\ObservedTerminalEpaContext;
use App\Services\CBB\TeamMetricSample;
use App\Services\Epa\StateBaselineService;
use App\Services\NBA\TrueEpaCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function observedTerminalEpaFixture(int $period = 2, int $awayScore = 2): array
{
    $home = Team::factory()->create(['espn_id' => '130']);
    $away = Team::factory()->create(['espn_id' => '41']);
    $game = Game::factory()->create(['espn_event_id' => '401000001', 'season' => 2027, 'season_type' => 2,
        'game_date' => now()->subDay()->toDateString(), 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 3, 'away_score' => $awayScore, 'period' => $period, 'game_clock' => '0:00']);
    foreach ([$home, $away] as $team) {
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $team->id, 'points' => $team->id === $home->id ? 3 : $awayScore,
            'field_goals_made' => $team->id === $home->id || $awayScore === 2 ? 1 : 0, 'field_goals_attempted' => 3, 'three_point_made' => $team->id === $home->id ? 1 : 0, 'three_point_attempted' => 1,
            'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
    }
    foreach ([['start', 'Jumpball', null, 0, 0, '20:00'], ['home', 'JumpShot', $home->id, 3, 0, '1:00'],
        ['away', 'JumpShot', $away->id, 3, $awayScore, '0:01'], ['end', 'End Game', null, 3, $awayScore, '0:00']] as $i => [$id, $type, $team, $h, $a, $clock]) {
        Play::factory()->create(['game_id' => $game->id, 'espn_play_id' => $id, 'sequence_number' => $i,
            'play_type' => $type, 'play_text' => $type, 'possession_team_id' => $team,
            'period' => $id === 'start' ? 1 : $period, 'clock' => $clock, 'home_score' => $h, 'away_score' => $a,
            'true_epa' => null, 'epa_calculated_at' => null]);
    }

    return [$game, $home, $away];
}

it('admits a complete observed final CBB sequence through its explicit terminal state', function (int $period, int $awayScore) {
    [$game, $home] = observedTerminalEpaFixture($period, $awayScore);
    app(CalculateGamePlayEpa::class)->execute($game);
    $sample = app(TeamMetricSample::class);
    $games = $sample->forTeam($home, 2027);
    expect($games->modelKeys())->toBe([$game->id]);
    expect(Play::where('game_id', $game->id)->where('espn_play_id', 'away')->value('true_epa'))->not->toBeNull();
    expect($sample->missingEpaGameIds($games))->toBe([]);
    $metric = TeamMetric::factory()->create(['team_id' => $home->id, 'season' => 2027, 'games_played' => 1]);
    expect(app(RefreshTeamPlayEpa::class)->execute($home, 2027))->toBeTrue()
        ->and($metric->fresh()->net_true_epa_per_play)->not->toBeNull();
    $receipt = ModelRun::where('run_type', 'play_epa')->sole();
    expect($receipt->metadata['source_hash'])->toHaveLength(64)
        ->and($receipt->model_version)->toBe(ObservedTerminalEpaContext::POLICY);
})->with([[2, 2], [3, 2], [2, 0]]);

it('refuses absent inconsistent or noncurrent terminal authority', function (string $defect) {
    [$game] = observedTerminalEpaFixture();
    $last = Play::where('game_id', $game->id)->where('espn_play_id', 'end')->sole();
    match ($defect) {
        'missing' => $last->delete(),
        'description only' => $last->update(['play_type' => 'Timeout', 'play_text' => 'End of Game']),
        'mismatch' => $last->update(['home_score' => 4]),
        'unknown score' => $last->update(['away_score' => null]),
        'postponed' => $game->update(['status' => 'STATUS_POSTPONED']),
        'period' => $game->update(['period' => 3]),
        'clock' => $game->update(['game_clock' => '0:01']),
        'unknown play clock' => Play::where('game_id', $game->id)->where('espn_play_id', 'away')->update(['clock' => null]),
        'team' => Play::where('game_id', $game->id)->where('espn_play_id', 'away')->update(['possession_team_id' => Team::factory()->create()->id]),
        'extra later' => Play::factory()->create(['game_id' => $game->id, 'espn_play_id' => 'later', 'sequence_number' => 4, 'period' => 2, 'clock' => '0:00', 'play_type' => 'Timeout', 'possession_team_id' => null, 'home_score' => 3, 'away_score' => 2]),
    };
    $plays = Play::where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
    expect(ObservedTerminalEpaContext::capture($game->fresh(), $plays))->toBeNull();
})->with(['missing', 'description only', 'mismatch', 'unknown score', 'postponed', 'period', 'clock', 'unknown play clock', 'team', 'extra later']);

it('refuses caller mutation of a prepared terminal observation', function () {
    [$game] = observedTerminalEpaFixture();
    $plays = Play::where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
    $context = ObservedTerminalEpaContext::capture($game, $plays);
    $plays->last()->home_score = 7;
    expect(fn () => $context->assertCalculatorInputs($plays, $game->home_team_id, $game->away_team_id, $game->season))->toThrow(RuntimeException::class);
});

it('rechecks current game authority before persisting any calculated EPA or receipt', function () {
    [$game] = observedTerminalEpaFixture();
    $before = Play::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($game, $real) {
        Game::whereKey($game->id)->update(['status' => 'STATUS_POSTPONED']);

        return $real->calculateForGame(...$arguments);
    });
    expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'source changed');
    expect(Play::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0);
});

it('guards receipt instance methods without claiming a privileged SQL boundary', function () {
    $this->travelTo(now()->startOfSecond());
    [$game] = observedTerminalEpaFixture();
    app(CalculateGamePlayEpa::class)->execute($game);
    $receipt = ModelRun::where('run_type', 'play_epa')->sole();
    $before = $receipt->getAttributes();
    foreach (['save', 'quiet', 'numeric', 'delete', 'quiet delete', 'reclassify'] as $method) {
        $run = $receipt->fresh();
        expect(fn () => match ($method) {
            'save' => $run->update(['status' => 'failed']),
            'quiet' => $run->updateQuietly(['metadata' => []]),
            'numeric' => $run->increment('code_version'),
            'delete' => $run->delete(),
            'quiet delete' => $run->deleteQuietly(),
            'reclassify' => $run->update(['run_type' => 'prediction']),
        })->toThrow(LogicException::class);
        expect($receipt->fresh()->getAttributes())->toBe($before);
    }
    $this->travel(1)->seconds();
    expect($receipt->fresh()->save())->toBeTrue()->and($receipt->fresh()->getAttributes())->toBe($before);
    $ordinary = ModelRun::create([...$receipt->only($receipt->getFillable()), 'id' => (string) Str::uuid(), 'run_type' => 'independent_test']);
    expect($ordinary->update(['status' => 'failed']))->toBeTrue();
    expect(fn () => $ordinary->update(['run_type' => 'play_epa']))->toThrow(LogicException::class);
    // Deliberate bulk SQL is an existing trusted-caller boundary, not an instance API.
    expect(ModelRun::whereKey($receipt->id)->update(['status' => 'bulk_boundary']))->toBe(1)
        ->and(ModelRun::whereKey($receipt->id)->delete())->toBe(1);
});

it('refuses current oversized source projection before changing EPA or inserting a receipt', function () {
    [$game] = observedTerminalEpaFixture();
    Play::where('game_id', $game->id)->where('espn_play_id', 'away')->update(['play_text' => str_repeat('é', 1025)]);
    expect(fn () => app(CalculateGamePlayEpa::class)->execute($game))->toThrow(RuntimeException::class, 'projection policy');
    expect(Play::where('game_id', $game->id)->whereNotNull('true_epa')->count())->toBe(0)
        ->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0);
});

it('refuses opaque SQL expressions that would retrofit ordinary runs into EPA receipts', function () {
    [$game] = observedTerminalEpaFixture();
    app(CalculateGamePlayEpa::class)->execute($game);
    $receipt = ModelRun::where('run_type', 'play_epa')->sole();
    $ordinary = ModelRun::create([...$receipt->only($receipt->getFillable()), 'id' => (string) Str::uuid(), 'run_type' => 'independent_test']);
    expect(fn () => $ordinary->update(['sport' => 'cbb', 'run_type' => DB::raw("'play_epa'")]))->toThrow(LogicException::class);
});

it('keeps an unknown eligible tail unavailable without observed terminal authority', function () {
    [$game] = observedTerminalEpaFixture();
    Play::where('game_id', $game->id)->where('espn_play_id', 'end')->delete();
    app(CalculateGamePlayEpa::class)->execute($game);
    $tail = Play::where('game_id', $game->id)->where('espn_play_id', 'away')->sole();
    expect($tail->true_epa)->toBeNull()->and($tail->epa_calculated_at)->toBeNull()
        ->and(ModelRun::where('run_type', 'play_epa')->sole()->metadata['terminal_authorized'])->toBeFalse();
});

it('does not apply CBB terminal evidence to the generic NBA calculation path', function () {
    [$game] = observedTerminalEpaFixture();
    $plays = Play::where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
    $context = ObservedTerminalEpaContext::capture($game, $plays);
    expect(fn () => app(TrueEpaCalculator::class)->calculateForGame($plays, $game->home_team_id, $game->away_team_id, $game->season, 'nba', $context))->toThrow(RuntimeException::class);
});

it('admits its exact source row boundary and refuses an extra row before mutation', function () {
    [$game] = observedTerminalEpaFixture();
    Play::where('game_id', $game->id)->where('espn_play_id', 'end')->update(['sequence_number' => 4095]);
    $rows = [];
    for ($i = 3; $i < 4095; $i++) {
        $rows[] = ['game_id' => $game->id, 'espn_play_id' => 'admin-'.$i, 'sequence_number' => $i,
            'period' => 2, 'clock' => '0:00', 'play_type' => 'Timeout', 'play_text' => 'Timeout',
            'possession_team_id' => null, 'home_score' => 3, 'away_score' => 2];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        Play::insert($chunk);
    }
    expect(Play::where('game_id', $game->id)->count())->toBe(4096);
    app(CalculateGamePlayEpa::class)->execute($game);
    expect(ModelRun::where('run_type', 'play_epa')->count())->toBe(1);
    Play::insert([...$rows[0], 'espn_play_id' => 'extra', 'sequence_number' => 4096]);
    $before = Play::where('game_id', $game->id)->whereNotNull('true_epa')->pluck('true_epa', 'id')->all();
    expect(fn () => app(CalculateGamePlayEpa::class)->execute($game))->toThrow(RuntimeException::class, 'play-count policy');
    expect(Play::where('game_id', $game->id)->whereNotNull('true_epa')->pluck('true_epa', 'id')->all())->toBe($before)
        ->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(1);
});

function observedTerminalBaseline(Game $game): EpaStateBaseline
{
    config(['epa.state_baseline.enabled' => true]);
    $cache = app(StateBaselineService::class);
    $cache->getMap('cbb', (int) $game->season);
    app()->instance(StateBaselineService::class, $cache);
    $away = Play::where('game_id', $game->id)->where('espn_play_id', 'away')->sole();
    $key = app(TrueEpaCalculator::class)->stateKeyForPlay($away, true, 1200);

    return EpaStateBaseline::create(['sport' => 'cbb', 'season' => $game->season,
        'source_season' => $game->season - 1, 'state_key' => $key, 'expected_points' => 1.25, 'sample_size' => 12]);
}

it('preserves enabled-baseline partial known transitions while holding an unobserved tail', function () {
    [$game, $home] = observedTerminalEpaFixture();
    observedTerminalBaseline($game);
    Play::where('game_id', $game->id)->where('espn_play_id', 'end')->delete();
    app(CalculateGamePlayEpa::class)->execute($game);
    expect((float) Play::where('game_id', $game->id)->where('espn_play_id', 'home')->value('expected_points_before'))->toBe(1.25)
        ->and(Play::where('game_id', $game->id)->where('espn_play_id', 'home')->value('true_epa'))->not->toBeNull()
        ->and(Play::where('game_id', $game->id)->where('espn_play_id', 'away')->value('true_epa'))->toBeNull();
    $sample = app(TeamMetricSample::class);
    expect($sample->missingEpaGameIds($sample->forTeam($home, 2027)))->toBe([$game->id]);
    $receipt = ModelRun::where('run_type', 'play_epa')->sole();
    expect($receipt->metadata['terminal_authorized'])->toBeFalse()
        ->and($receipt->metadata['baseline_source_hash'])->toHaveLength(64);
});

it('binds equal-valued new baseline source rows without changing model math', function () {
    [$game] = observedTerminalEpaFixture();
    $baseline = observedTerminalBaseline($game);
    app(CalculateGamePlayEpa::class)->execute($game);
    $first = ModelRun::where('run_type', 'play_epa')->sole();
    $values = Play::where('game_id', $game->id)->orderBy('id')->pluck('true_epa', 'id')->all();
    $attributes = $baseline->only($baseline->getFillable());
    $baseline->delete();
    EpaStateBaseline::create($attributes);
    app(CalculateGamePlayEpa::class)->execute($game);
    $second = ModelRun::where('run_type', 'play_epa')->where('id', '!=', $first->id)->sole();
    expect(Play::where('game_id', $game->id)->orderBy('id')->pluck('true_epa', 'id')->all())->toBe($values)
        ->and($second->metadata['source_hash'])->not->toBe($first->metadata['source_hash'])
        ->and($second->metadata['baseline_source_hash'])->not->toBe($first->metadata['baseline_source_hash']);
});

it('refuses baseline drift between preparation and persistence on the same connection', function () {
    [$game] = observedTerminalEpaFixture();
    $baseline = observedTerminalBaseline($game);
    $real = app(TrueEpaCalculator::class);
    $mock = Mockery::mock(TrueEpaCalculator::class);
    $mock->shouldReceive('calculateForGame')->once()->andReturnUsing(function (...$arguments) use ($baseline, $real) {
        EpaStateBaseline::whereKey($baseline->id)->update(['expected_points' => 9]);

        return $real->calculateForGame(...$arguments);
    });
    expect(fn () => (new CalculateGamePlayEpa($mock))->execute($game))->toThrow(RuntimeException::class, 'source changed');
    expect((float) $baseline->fresh()->expected_points)->toBe(1.25)
        ->and(Play::where('game_id', $game->id)->whereNotNull('true_epa')->count())->toBe(0)
        ->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0);
});

it('refuses malformed persisted raw clock sequence and score facts before terminal admission', function (string $defect) {
    [$game] = observedTerminalEpaFixture(2, 0);
    $field = match ($defect) {
        'bad terminal zero score', 'bad interior zero score' => 'away_score',
        'fractional sequence' => 'sequence_number',
        default => 'period',
    };
    $value = str_starts_with($defect, 'bad ') ? 'bad' : 2.5;
    if ($defect === 'fractional game period') {
        DB::table('cbb_games')->where('id', $game->id)->update([$field => $value]);
    } else {
        $id = in_array($defect, ['bad interior zero score', 'fractional interior period'], true) ? 'home' : 'end';
        DB::table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', $id)->update([$field => $value]);
    }
    DB::transaction(function () use ($game, $defect, $field, $value) {
        $current = Game::query()->useWritePdo()->select(ObservedTerminalEpaContext::projection($game->getConnection(), ObservedTerminalEpaContext::GAME_FIELDS))->whereKey($game->id)->lockForUpdate()->firstOrFail();
        $plays = Play::query()->useWritePdo()->select(ObservedTerminalEpaContext::projection($game->getConnection(), ObservedTerminalEpaContext::SOURCE_FIELDS))->where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->lockForUpdate()->get();
        $raw = $defect === 'fractional game period' ? $current->getRawOriginal($field) : $plays->firstWhere('espn_play_id', in_array($defect, ['bad interior zero score', 'fractional interior period'], true) ? 'home' : 'end')->getRawOriginal($field);
        expect($raw)->toBe($value);
        expect(ObservedTerminalEpaContext::capture($current, $plays))->toBeNull();
    });
})->with(['bad terminal zero score', 'bad interior zero score', 'fractional terminal period', 'fractional interior period', 'fractional game period', 'fractional sequence']);

it('keeps raw score identity distinct even when an Eloquent cast aliases malformed text to zero', function () {
    [$game] = observedTerminalEpaFixture(2, 0);
    $plays = Play::where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
    $known = ObservedTerminalEpaContext::sourceHash($game, $plays, [], null);
    DB::table('cbb_plays')->where('game_id', $game->id)->where('espn_play_id', 'end')->update(['away_score' => 'bad']);
    $plays = Play::where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
    foreach ($plays as $play) {
        $play->mergeCasts(['away_score' => 'integer', 'period' => 'integer', 'sequence_number' => 'integer']);
    }
    expect($plays->last()->getRawOriginal('away_score'))->toBe('bad')->and($plays->last()->away_score)->toBe(0);
    expect(ObservedTerminalEpaContext::capture($game, $plays))->toBeNull()
        ->and(ObservedTerminalEpaContext::sourceHash($game, $plays, [], null))->not->toBe($known);
});

it('refuses cancelled CBB receipt persistence before any EPA writes', function (string $event) {
    [$game] = observedTerminalEpaFixture();
    $before = Play::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $original = ModelRun::getEventDispatcher();
    ModelRun::setEventDispatcher(clone $original);
    $attempts = 0;
    ModelRun::{$event}(function (ModelRun $run) use (&$attempts) {
        if ($run->sport === 'cbb' && $run->run_type === 'play_epa') {
            $attempts++;

            return false;
        }
    });
    $failure = null;
    try {
        app(CalculateGamePlayEpa::class)->execute($game);
    } catch (RuntimeException $exception) {
        $failure = $exception;
    } finally {
        ModelRun::setEventDispatcher($original);
    }
    expect($attempts)->toBe(1)
        ->and(ModelRun::where('run_type', 'play_epa')->count())->toBe(0)
        ->and(Play::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and($failure)->toBeInstanceOf(RuntimeException::class);
})->with(['saving', 'creating']);
