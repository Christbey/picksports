<?php

use App\Actions\MLB\CalculateElo;
use App\Exceptions\EloAdmissionRefused;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSourceContext;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriterProtocol;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function selectedMlbHistoryFixture(bool $reviewed = false): array
{
    config(['app.timezone' => 'UTC', 'elo_integrity.enabled' => true]);
    test()->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    Http::preventStrayRequests();
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'mlb' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('mlb', 'team');
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'admitting', 'protocol_version' => 1,
        'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
        'drain_reference' => 'synthetic-selected-history-drain', 'drain_verified_at' => now()->subMinute()]);
    $teams = Team::factory()->count(2)->create(['elo_rating' => $reviewed ? 1600 : 1500]);
    $pitchers = collect($teams)->map(fn ($team) => Player::factory()->pitcher()->create(['team_id' => $team->id, 'elo_rating' => $reviewed ? 1550 : 1500]));
    $game = null;
    if ($reviewed) {
        $game = selectedMlbHistoryGame($teams);
        foreach ($teams as $index => $team) {
            EloRating::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => 2026, 'week' => 1,
                'date' => '2026-06-01', 'elo_rating' => 1600, 'elo_change' => 0]);
            PitcherEloRating::create(['player_id' => $pitchers[$index]->id, 'team_id' => $team->id,
                'game_id' => $game->id, 'season' => 2026, 'date' => '2026-06-01',
                'elo_rating' => 1550, 'elo_change' => 0, 'games_started' => 1]);
        }
    }
    $admission = app(EloBaselineAdmission::class);
    foreach (['team' => $teams, 'pitcher' => $pitchers] as $type => $entities) {
        $ids = $entities->pluck('id')->all();
        $report = $admission->inspect('mlb', 2026, $type, $ids);
        $admission->apply('mlb', 2026, $type, $ids, $report['fingerprint'], 'synthetic-exact-selected-history-review');
    }
    config(['elo_integrity.write_target_fingerprints.mlb' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'active']);
    if (! $reviewed) {
        $game = selectedMlbHistoryGame($teams);
        foreach ($teams as $index => $team) {
            PlayerStat::factory()->pitching()->create(['game_id' => $game->id, 'team_id' => $team->id,
                'player_id' => $pitchers[$index]->id, 'innings_pitched' => '6.0']);
        }
        (new CalculateElo)->execute($game);
    }

    return compact('teams', 'pitchers', 'game');
}

function selectedMlbHistoryGame($teams, string $date = '2026-06-01', string $status = 'STATUS_FINAL'): Game
{
    return Game::factory()->withSyntheticWallStart('UTC')->create(['home_team_id' => $teams[0]->id,
        'away_team_id' => $teams[1]->id, 'season' => 2026, 'season_type' => 2, 'week' => 1,
        'game_date' => $date, 'game_time' => '18:00:00', 'status' => $status,
        'home_score' => $status === 'STATUS_FINAL' ? 4 : null, 'away_score' => $status === 'STATUS_FINAL' ? 1 : null]);
}

function proveSelectedMlbHistories(array $fixture, array $requests): array
{
    return DB::connection()->transaction(function () use ($fixture, $requests): array {
        $connection = DB::connection();
        $registry = app(EloSportRegistry::class);
        $definition = $registry->resolve('mlb', 'team');
        $protocol = app(EloWriterProtocol::class);
        $protocol->lockGate($connection, 'mlb', $definition);
        $sources = app(EloSourceContext::class);
        $sources->beginObservation();
        $participants = ['team' => $fixture['teams']->pluck('id')->all(), 'pitcher' => $fixture['pitchers']->pluck('id')->all()];
        $locked = $sources->lockGames($connection, 'mlb', $definition, $participants, [], 2026, [2026]);
        $heads = [];
        foreach (['team', 'pitcher'] as $type) {
            $entityDefinition = $registry->resolve('mlb', $type);
            $entities = $entityDefinition['entity_model']::query()->whereIn('id', $participants[$type])->orderBy('id')->lockForUpdate()->get();
            foreach ($entities as $entity) {
                $heads[] = $protocol->lockHead($connection, 'mlb', $type, $entity, $entityDefinition);
            }
        }

        return $sources->verifyDependencies($connection, 'mlb', $definition, $locked, $heads, requestedHistory: $requests);
    });
}

it('returns exact native team and pitcher history with immutable linkage in selected order', function () {
    $fixture = selectedMlbHistoryFixture();
    $teamHistory = EloRating::where('team_id', $fixture['teams'][0]->id)->firstOrFail();
    $pitcherHistory = PitcherEloRating::where('player_id', $fixture['pitchers'][1]->id)->firstOrFail();
    $requests = [['entity_type' => 'pitcher', 'entity_id' => $pitcherHistory->player_id, 'history_id' => $pitcherHistory->id],
        ['entity_type' => 'team', 'entity_id' => $teamHistory->team_id, 'history_id' => $teamHistory->id]];
    $proof = proveSelectedMlbHistories($fixture, $requests);
    expect(array_keys($proof))->toBe(['pitcher:'.$pitcherHistory->player_id.':'.$pitcherHistory->id, 'team:'.$teamHistory->team_id.':'.$teamHistory->id]);
    foreach ($proof as $item) {
        expect($item['lineage']['kind'])->toBe('native_effect')
            ->and($item['lineage']['effect_id'])->toBeGreaterThan(0)
            ->and($item['lineage']['application_hash'])->toHaveLength(64)
            ->and($item['history']['date'])->toBe('2026-06-01');
    }
    expect(array_values($proof)[0]['history']['rating'])->toBe(number_format((float) $pitcherHistory->elo_rating, 4, '.', ''))
        ->and(array_values($proof)[0]['history']['games_started'])->toBe(1);
});

it('returns only exact reviewed baseline source rows without inventing native applications', function () {
    $fixture = selectedMlbHistoryFixture(true);
    $team = EloRating::where('team_id', $fixture['teams'][0]->id)->firstOrFail();
    $pitcher = PitcherEloRating::where('player_id', $fixture['pitchers'][0]->id)->firstOrFail();
    $proof = proveSelectedMlbHistories($fixture, [['entity_type' => 'team', 'entity_id' => $team->team_id, 'history_id' => $team->id],
        ['entity_type' => 'pitcher', 'entity_id' => $pitcher->player_id, 'history_id' => $pitcher->id]]);
    expect(SportEloApplication::count())->toBe(0);
    foreach ($proof as $item) {
        expect($item['lineage']['kind'])->toBe('reviewed_baseline')
            ->and($item['lineage'])->not->toHaveKey('application_id')
            ->and($item['lineage']['initialization_id'])->toBeGreaterThan(0);
    }
});

it('refuses an equal numeric history row without a retained link and preserves prior facts', function () {
    $fixture = selectedMlbHistoryFixture();
    $game = selectedMlbHistoryGame($fixture['teams'], '2026-06-02', 'STATUS_SCHEDULED');
    $row = EloRating::create(['team_id' => $fixture['teams'][0]->id, 'game_id' => $game->id, 'season' => 2026,
        'date' => '2026-06-02', 'elo_rating' => $fixture['teams'][0]->fresh()->elo_rating, 'elo_change' => 0]);
    $before = [EloRating::orderBy('id')->get()->toArray(), SportEloApplication::orderBy('id')->get()->toArray()];
    expect(fn () => proveSelectedMlbHistories($fixture, [['entity_type' => 'team', 'entity_id' => $row->team_id, 'history_id' => $row->id]]))
        ->toThrow(EloAdmissionRefused::class, 'selected_history_unbound');
    expect([EloRating::orderBy('id')->get()->toArray(), SportEloApplication::orderBy('id')->get()->toArray()])->toBe($before);
});

it('refuses selected history when its source correction invalidates the graph', function () {
    $fixture = selectedMlbHistoryFixture();
    $row = EloRating::where('team_id', $fixture['teams'][0]->id)->firstOrFail();
    Game::whereKey($fixture['game']->id)->update(['away_score' => 7]);
    expect(fn () => proveSelectedMlbHistories($fixture, [['entity_type' => 'team', 'entity_id' => $row->team_id, 'history_id' => $row->id]]))
        ->toThrow(EloAdmissionRefused::class, 'transitive_dependency_source_correction');
});

it('does not turn an older reviewed-prefix row into exact baseline history evidence', function () {
    $fixture = selectedMlbHistoryFixture(true);
    $older = selectedMlbHistoryGame($fixture['teams'], '2026-05-01');
    $rows = [];
    foreach ($fixture['teams'] as $team) {
        $rows[] = EloRating::create(['team_id' => $team->id, 'game_id' => $older->id, 'season' => 2026, 'week' => 1,
            'date' => '2026-05-01', 'elo_rating' => 1600, 'elo_change' => 0]);
    }
    expect(fn () => proveSelectedMlbHistories($fixture, [['entity_type' => 'team', 'entity_id' => $rows[0]->team_id, 'history_id' => $rows[0]->id]]))
        ->toThrow(EloAdmissionRefused::class, 'selected_history_unbound');
    expect(SportEloApplication::count())->toBe(0)->and($rows[0]->fresh()->elo_rating)->toBe('1600.0');
});

it('refuses the right history ID attributed to the wrong selected actor', function () {
    $fixture = selectedMlbHistoryFixture();
    $row = EloRating::where('team_id', $fixture['teams'][0]->id)->firstOrFail();
    expect(fn () => proveSelectedMlbHistories($fixture, [['entity_type' => 'team', 'entity_id' => $fixture['teams'][1]->id, 'history_id' => $row->id]]))
        ->toThrow(EloAdmissionRefused::class, 'selected_history_unbound');
});

it('refuses current history drift instead of returning the retained old number', function () {
    $fixture = selectedMlbHistoryFixture();
    $row = PitcherEloRating::where('player_id', $fixture['pitchers'][0]->id)->firstOrFail();
    $later = selectedMlbHistoryGame($fixture['teams'], '2026-06-02');
    foreach ($fixture['teams'] as $index => $team) {
        PlayerStat::factory()->pitching()->create(['game_id' => $later->id, 'team_id' => $team->id,
            'player_id' => $fixture['pitchers'][$index]->id, 'innings_pitched' => '6.0']);
    }
    (new CalculateElo)->execute($later);
    PitcherEloRating::whereKey($row->id)->update(['elo_rating' => 1700]);
    expect(fn () => proveSelectedMlbHistories($fixture, [['entity_type' => 'pitcher', 'entity_id' => $row->player_id, 'history_id' => $row->id]]))
        ->toThrow(EloAdmissionRefused::class, 'dependency_effective_history_changed');
});

it('returns identical lineage for equivalent repeated selections without request timestamps', function () {
    $fixture = selectedMlbHistoryFixture();
    $row = EloRating::where('team_id', $fixture['teams'][0]->id)->firstOrFail();
    $requests = [['entity_type' => 'team', 'entity_id' => $row->team_id, 'history_id' => $row->id]];
    $first = proveSelectedMlbHistories($fixture, $requests);
    test()->travel(5)->seconds();
    expect(proveSelectedMlbHistories($fixture, $requests))->toBe($first);
});
