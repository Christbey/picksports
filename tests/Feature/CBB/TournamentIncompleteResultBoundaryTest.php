<?php

use App\Actions\CBB\GenerateTournamentForecast;
use App\Actions\CBB\RecalculateTournamentOutlook;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TournamentForecast;
use App\Models\CBB\TournamentStateSnapshot;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

// Synthetic observed-result admission; no provider or model boundaries are mocked.
beforeEach(function () {
    $this->travelTo('2026-03-21 12:00:00');
    Http::preventStrayRequests();
    Cache::flush();
    config(['cbb_bracket.season_fallbacks.2026' => []]);
});

afterEach(fn () => $this->travelBack());

function tournamentIncompleteRaw(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

function tournamentIncompleteTrace(array $payload): void
{
    $path = getenv('REVIEW_CBB_TOURNAMENT_TRACE');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}

function tournamentIncompleteTeams(): array
{
    $teams = [];
    for ($i = 0; $i < 10; $i++) {
        $team = Team::factory()->create([
            'school' => 'Observation Team '.$i, 'mascot' => 'Fixture '.$i,
            'abbreviation' => 'OBS'.$i, 'conference' => ['SEC', 'ACC', 'Big 12', 'Big Ten'][$i % 4],
            'elo_rating' => 1780 - 30 * $i,
        ]);
        TeamMetric::create([
            'team_id' => $team->id, 'season' => 2026, 'meets_minimum' => true,
            'offensive_efficiency' => 112 - $i, 'defensive_efficiency' => 98 + .8 * $i,
            'net_rating' => 14 - 1.8 * $i, 'tempo' => 69, 'strength_of_schedule' => 1590 - 8 * $i,
            'games_played' => 28, 'adj_offensive_efficiency' => 113 - .9 * $i,
            'adj_defensive_efficiency' => 97 + .7 * $i, 'adj_net_rating' => 16 - 2 * $i,
            'rolling_offensive_efficiency' => 111 - .8 * $i,
            'rolling_defensive_efficiency' => 99 + .7 * $i, 'rolling_net_rating' => 12 - 1.6 * $i,
            'rolling_tempo' => 69, 'rolling_games_count' => 10, 'calculation_date' => '2026-03-21',
        ]);
        $teams[] = $team;
    }

    return $teams;
}

it('does not turn incomplete final scores into observed tournament selection records', function (?int $home, ?int $away, string $status, ?string $winner) {
    $teams = tournamentIncompleteTeams();
    $config = ['field_size' => 8, 'auto_bids' => 3, 'random_seed' => 12345];
    $tables = ['cbb_games', 'cbb_team_metrics', 'cbb_tournament_forecasts', 'cbb_tournament_state_snapshots'];
    $beforeAbsent = collect($tables)->mapWithKeys(fn ($table) => [$table => tournamentIncompleteRaw($table)])->all();
    $absent = app(GenerateTournamentForecast::class)->simulateForBacktest(2026, 100, $config)->keyBy('team_id')->sortKeys()->all();
    $repeated = app(GenerateTournamentForecast::class)->simulateForBacktest(2026, 100, $config)->keyBy('team_id')->sortKeys()->all();
    $afterAbsent = collect($tables)->mapWithKeys(fn ($table) => [$table => tournamentIncompleteRaw($table)])->all();
    $game = Game::factory()->create([
        'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => 2, 'status' => $status,
        'game_date' => '2026-03-20', 'game_time' => '18:00:00', 'home_score' => $home, 'away_score' => $away,
    ]);
    $beforeVariant = collect($tables)->mapWithKeys(fn ($table) => [$table => tournamentIncompleteRaw($table)])->all();
    $actual = app(GenerateTournamentForecast::class)->simulateForBacktest(2026, 100, $config)->keyBy('team_id')->sortKeys()->all();
    $afterVariant = collect($tables)->mapWithKeys(fn ($table) => [$table => tournamentIncompleteRaw($table)])->all();
    tournamentIncompleteTrace(['kind' => 'generator', 'case' => [$home, $away, $status], 'game_id' => $game->id,
        'absent' => $absent, 'repeated' => $repeated, 'actual' => $actual,
        'before_absent' => $beforeAbsent, 'after_absent' => $afterAbsent,
        'before_variant' => $beforeVariant, 'after_variant' => $afterVariant]);

    expect($repeated)->toBe($absent)->and($afterAbsent)->toBe($beforeAbsent)->and($afterVariant)->toBe($beforeVariant);
    expect($actual)->toHaveCount(10);
    if ($winner === null) {
        expect($actual)->toBe($absent);
    } else {
        foreach (['home' => $teams[0], 'away' => $teams[1]] as $side => $team) {
            expect($actual[$team->id]['wins'])->toBe($side === $winner ? 1 : 0)
                ->and($actual[$team->id]['losses'])->toBe($side === $winner ? 0 : 1)
                ->and((float) $actual[$team->id]['win_pct'])->toBe($side === $winner ? 1.0 : 0.0);
        }
    }
    Http::assertNothingSent();
})->with([
    'missing away final' => [80, null, 'STATUS_FINAL', null],
    'missing home final' => [null, 75, 'STATUS_FINAL', null],
    'both missing final' => [null, null, 'STATUS_FINAL', null],
    'observed home zero' => [0, 70, 'STATUS_FINAL', 'away'],
    'observed away zero' => [70, 0, 'STATUS_FINAL', 'home'],
    'complete home winner' => [80, 75, 'STATUS_FINAL', 'home'],
    'complete away winner' => [75, 80, 'STATUS_FINAL', 'away'],
    'nonfinal lead' => [80, 75, 'STATUS_IN_PROGRESS', null],
]);

function tournamentIncompleteNative(Game $game): void
{
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true, 'prediction_lifecycle.canonical_reads.cbb' => true]);
    $event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game->update(['sport_event_id' => $event->id, 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s')]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'cbb', 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create([
        'sport' => 'cbb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['event' => ['starts_at' => $event->starts_at->toIso8601String()],
            'home' => ['team_id' => $game->home_team_id, 'elo' => 1550],
            'away' => ['team_id' => $game->away_team_id, 'elo' => 1490]],
    ]);
    $run = CalculationRun::factory()->create(['output_hash' => str_repeat('a', 64), 'sport_event_id' => $event->id,
        'event_input_snapshot_id' => $input->id, 'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['output_hash' => str_repeat('a', 64), 'sport' => 'cbb',
        'sport_event_id' => $event->id, 'calculation_run_id' => $run->id, 'publication_state' => 'draft',
        'phase' => 'pregame', 'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, .7], ['spread', 'home', -4.0, null], ['total', 'combined', 145.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);
}

it('refuses incomplete final outlook results without publishing completed winner certainty', function (?int $home, ?int $away, string $status, ?string $winner) {
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
    $teams = tournamentIncompleteTeams();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => config('cbb.season.types.postseason'),
        'is_ncaa_tournament' => true, 'tournament_region' => 'East', 'tournament_round' => 'round_of_64',
        'home_seed' => 1, 'away_seed' => 16, 'game_date' => '2026-03-20', 'game_time' => '18:00:00',
        'status' => $status, 'home_score' => $home, 'away_score' => $away]);
    if ($status !== 'STATUS_FINAL') {
        tournamentIncompleteNative($game);
    }
    $prior = TournamentStateSnapshot::query()->create(['season' => 2025, 'as_of' => now()->subYear(),
        'source' => 'synthetic-prior-completed', 'status' => 'completed', 'games_final_count' => 0,
        'games_remaining_count' => 1, 'field_size' => 2]);
    foreach ([$teams[0], $teams[1]] as $team) {
        TournamentForecast::factory()->create(['team_id' => $team->id, 'season' => 2025, 'snapshot_id' => $prior->id,
            'mode' => 'live', 'is_alive' => true, 'is_eliminated' => false,
            'round_of_32_probability' => .5, 'champion_probability' => .12]);
    }
    $inputRows = ['games' => tournamentIncompleteRaw('cbb_games'), 'metrics' => tournamentIncompleteRaw('cbb_team_metrics')];
    $priorRows = ['snapshot' => (array) DB::table('cbb_tournament_state_snapshots')->where('id', $prior->id)->first(),
        'forecasts' => DB::table('cbb_tournament_forecasts')->where('snapshot_id', $prior->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    $caught = null;
    try {
        app(RecalculateTournamentOutlook::class)->execute(2026, 'synthetic-observation-control', $game->id);
    } catch (Throwable $error) {
        $caught = ['class' => $error::class, 'message' => $error->getMessage()];
    }
    Cache::flush();
    $response = $this->getJson('/api/v2/sports/cbb/forecasts?season=2026');
    $snapshots = tournamentIncompleteRaw('cbb_tournament_state_snapshots');
    $forecasts = tournamentIncompleteRaw('cbb_tournament_forecasts');
    tournamentIncompleteTrace(['kind' => 'outlook', 'case' => [$home, $away, $status], 'exception' => $caught,
        'snapshots' => $snapshots, 'forecasts' => $forecasts, 'response_status' => $response->status(),
        'response_json' => $response->json(), 'input_rows_before' => $inputRows,
        'input_rows_after' => ['games' => tournamentIncompleteRaw('cbb_games'), 'metrics' => tournamentIncompleteRaw('cbb_team_metrics')]]);

    expect(tournamentIncompleteRaw('cbb_games'))->toBe($inputRows['games'])
        ->and(tournamentIncompleteRaw('cbb_team_metrics'))->toBe($inputRows['metrics'])
        ->and((array) DB::table('cbb_tournament_state_snapshots')->where('id', $prior->id)->first())->toBe($priorRows['snapshot'])
        ->and(DB::table('cbb_tournament_forecasts')->where('snapshot_id', $prior->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($priorRows['forecasts']);
    $response->assertOk();
    $new = collect($snapshots)->where('id', '!=', $prior->id);
    if ($status === 'STATUS_FINAL' && ($home === null || $away === null)) {
        if ($caught !== null) {
            expect(is_a($caught['class'], QueryException::class, true))->toBeFalse();
        }
        expect($caught !== null || $new->isNotEmpty())->toBeTrue();
        expect($new->where('status', 'completed'))->toHaveCount(0);
        foreach ($new as $snapshot) {
            expect(in_array($snapshot['status'], ['failed', 'held'], true))->toBeTrue();
        }
        foreach ([$teams[0], $teams[1]] as $team) {
            $row = collect($response->json('data'))->firstWhere('team_id', $team->id);
            if ($row !== null) {
                expect($row)->toBeArray()->and($row['is_eliminated'])->toBeFalse();
            }
        }
    } else {
        expect($caught)->toBeNull()->and($new)->toHaveCount(1);
        $snapshot = $new->first();
        expect($snapshot['status'])->toBe('completed');
        $rows = collect($forecasts)->where('snapshot_id', $snapshot['id'])->keyBy('team_id');
        foreach (['home' => $teams[0], 'away' => $teams[1]] as $side => $team) {
            expect((bool) $rows[$team->id]['is_eliminated'])->toBe($winner !== null && $side !== $winner);
            $probability = $winner === null ? ($side === 'home' ? .7 : .3) : ($side === $winner ? 1.0 : 0.0);
            expect((float) $rows[$team->id]['round_of_32_probability'])->toBe($probability);
        }
    }
    Http::assertNothingSent();
})->with([
    'missing away final' => [80, null, 'STATUS_FINAL', null],
    'missing home final' => [null, 75, 'STATUS_FINAL', null],
    'both missing final' => [null, null, 'STATUS_FINAL', null],
    'observed home zero' => [0, 70, 'STATUS_FINAL', 'away'],
    'observed away zero' => [70, 0, 'STATUS_FINAL', 'home'],
    'complete home winner' => [80, 75, 'STATUS_FINAL', 'home'],
    'complete away winner' => [75, 80, 'STATUS_FINAL', 'away'],
    'selected native scheduled positive' => [null, null, 'STATUS_SCHEDULED', null],
]);
