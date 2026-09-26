<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('nfl', 'predictions');

class NflEvidenceReuseProbe extends GeneratePredictionFromHistoricalElo
{
    public function injuryState(int $teamId, Game $game): array
    {
        return $this->pointInTimeInjuryStateForTeam($teamId, $game);
    }

    public function movement(Game $game): ?float
    {
        return $this->historicalLineMovement($game);
    }

    public function analyze(Game $game): array
    {
        parent::applyAnalysisLayer($game, 8.0, 44.0, 0.7);

        return $this->lastModelMetadata['analysis_layer'];
    }

    protected function calculateForecast(Game $game, float $predictedSpread, float $winProbability, float $predictedTotal): array
    {
        // Keep this integration test focused on real generate() context lifecycle.
        return [$predictedSpread, $winProbability, $predictedTotal];
    }

    protected function applyAnalysisLayer(Game $game, float $predictedSpread, float $predictedTotal, float $winProbability): void
    {
        $this->lastModelMetadata['injury_probe'] = $this->injuryState($game->home_team_id, $game);
        $this->injuryState($game->home_team_id, $game);
        $this->lastModelMetadata['movement_probe'] = $this->movement($game);
    }
}

function nflEvidenceGame(): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026,
        'season_type' => '2',
        'week' => 3,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-27',
        'game_time' => '17:00:00',
        'odds_data' => nflEvidenceOdds(-2.5),
    ])->load(['homeTeam', 'awayTeam']);
}

function nflEvidenceOdds(float $homeLine): array
{
    return ['home_team' => 'Home', 'bookmakers' => [['markets' => [
        ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => $homeLine, 'price' => -110]]],
        ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 43.5, 'price' => -110]]],
    ]]]];
}

function nflEvidenceInjury(Game $game, string $observedAt, string $status = 'Out'): PlayerInjurySnapshot
{
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'position' => 'QB']);
    $snapshot = PlayerInjurySnapshot::create([
        'snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
        'espn_team_id' => $game->homeTeam->espn_id, 'provider' => 'test',
        'observed_at' => $observedAt, 'source_updated_at' => $observedAt,
        'payload_hash' => hash('sha256', $observedAt), 'entry_count' => 1,
    ]);
    $snapshot->entries()->create([
        'player_id' => $player->id, 'espn_athlete_id' => $player->espn_id,
        'injury_key' => 'test-'.$player->id, 'status' => $status,
        'observed_at' => $observedAt, 'source_updated_at' => $observedAt,
    ]);

    return $snapshot;
}

it('shares generator reason-code and pro-signal odds history in four bounded reads', function () {
    config(['nfl.predictions.analysis_layer.enabled' => true]);
    $game = nflEvidenceGame();
    for ($index = 0; $index < 1000; $index++) {
        GameOddsSnapshot::create([
            'sport' => 'nfl', 'game_table' => $game->getTable(), 'game_id' => $game->id,
            'captured_at' => now()->startOfDay()->addMinutes($index),
            'payload_hash' => (string) $index, 'odds_data' => nflEvidenceOdds(-1 - $index / 100),
        ]);
    }
    $retrieved = 0;
    GameOddsSnapshot::retrieved(function () use (&$retrieved) {
        $retrieved++;
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $action = app(NflEvidenceReuseProbe::class);

    expect($action->movement($game))->toBe(9.99);
    $analysis = $action->analyze($game);
    $action->movement($game);

    $queries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'game_odds_snapshots'))->values();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(4)
        ->and($retrieved)->toBe(3)
        ->and(data_get($analysis, 'pro_signal_layer.market_movement.open_spread'))->toBe(1.0)
        ->and(data_get($analysis, 'pro_signal_layer.market_movement.last_snapshot_spread'))->toBe(10.99);
    foreach ($queries->slice(1) as $query) {
        expect(strtolower($query['query']))->toContain('limit 1')->not->toContain('select *');
    }
});

it('memoizes injury evidence once per forecast and refreshes on the next preview', function () {
    $game = nflEvidenceGame();
    $old = nflEvidenceInjury($game, '2026-09-25 10:00:00');
    $action = app(NflEvidenceReuseProbe::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = $action->injuryState($game->home_team_id, $game);
    $initialQueries = count(DB::getQueryLog());
    expect($initialQueries)->toBe(3);

    expect($action->injuryState($game->home_team_id, $game))->toBe($first)
        ->and(count(DB::getQueryLog()))->toBe($initialQueries);
    DB::disableQueryLog();
    $new = nflEvidenceInjury($game, '2026-09-26 10:00:00', 'Questionable');
    expect($action->injuryState($game->home_team_id, $game)['snapshot_uuid'])->toBe($old->snapshot_uuid);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $preview = $action->preview($game);
    $snapshotQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'nfl_player_injury_snapshots'));
    DB::disableQueryLog();
    expect(data_get($preview, 'model_metadata.injury_probe.snapshot_uuid'))->toBe($new->snapshot_uuid)
        ->and($snapshotQueries)->toHaveCount(1);
});

it('keeps injury cache entries separate for team and kickoff', function () {
    $game = nflEvidenceGame();
    $old = nflEvidenceInjury($game, '2026-09-25 10:00:00');
    $new = nflEvidenceInjury($game, '2026-09-26 10:00:00');
    $early = clone $game;
    $early->game_date = '2026-09-25';
    $action = app(NflEvidenceReuseProbe::class);

    expect($action->injuryState($game->home_team_id, $game)['snapshot_uuid'])->toBe($new->snapshot_uuid)
        ->and($action->injuryState($game->home_team_id, $early)['snapshot_uuid'])->toBe($old->snapshot_uuid)
        ->and($action->injuryState($game->away_team_id, $game)['snapshot_uuid'])->toBeNull();
});
