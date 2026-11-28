<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\ModelRun;
use App\Services\CBB\ObservedTerminalEpaContext;
use App\Services\CBB\PinnedEpaBaseline;
use App\Services\NBA\TrueEpaCalculator;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalculateGamePlayEpa
{
    public function __construct(private TrueEpaCalculator $calculator) {}

    public function execute(Game $game): int
    {
        $connection = $game->getConnection();
        $connectionName = $connection->getName();
        $gameId = filter_var($game->getRawOriginal($game->getKeyName()) ?? $game->getKey(), FILTER_VALIDATE_INT);
        if (! is_string($connectionName) || $connectionName === '' || $gameId === false || $gameId <= 0 || $game->isDirty($game->getKeyName())) {
            throw new \RuntimeException('CBB EPA requires a stable persisted game and connection.');
        }
        $pdo = $connection->getPdo();
        $entryLevel = $connection->transactionLevel();
        try {
            return $connection->transaction(function () use ($connection, $connectionName, $gameId, $pdo): int {
                $ownedLevel = $connection->transactionLevel();
                $assertOwner = function () use ($connection, $connectionName, $pdo, $ownedLevel): void {
                    if (DB::connection($connectionName) !== $connection || $connection->getRawPdo() !== $pdo
                        || ! $pdo->inTransaction() || $connection->transactionLevel() !== $ownedLevel) {
                        throw new \RuntimeException('CBB EPA connection ownership changed during calculation.');
                    }
                };
                $ownedQuery = function (string $class) use ($assertOwner, $connection, $connectionName) {
                    $assertOwner();
                    $query = $class::on($connectionName);
                    if ($query->getQuery()->getConnection() !== $connection) {
                        throw new \RuntimeException('CBB EPA model resolved outside its owned connection.');
                    }

                    return $query;
                };
                $loadGame = function () use ($ownedQuery, $connection, $gameId, $assertOwner): Game {
                    $current = $ownedQuery(Game::class)->select(ObservedTerminalEpaContext::projection($connection, ObservedTerminalEpaContext::GAME_FIELDS))->useWritePdo()->whereKey($gameId)->lockForUpdate()->firstOrFail();
                    $assertOwner();
                    if ((int) $current->source_oversized !== 0) {
                        throw new \RuntimeException('CBB EPA current game exceeds its scalar policy.');
                    }

                    return $current;
                };
                $game = $loadGame();
                $query = fn () => $ownedQuery(Play::class)->useWritePdo()->where('game_id', $gameId);
                if ($query()->limit(ObservedTerminalEpaContext::MAXIMUM_PLAYS + 1)->pluck('id')->count() > ObservedTerminalEpaContext::MAXIMUM_PLAYS) {
                    throw new \RuntimeException('CBB EPA source exceeds its play-count policy.');
                }
                $bytes = $query()->selectRaw('COALESCE(SUM(COALESCE(LENGTH(play_text),0) + COALESCE(LENGTH(play_type),0) + COALESCE(LENGTH(espn_play_id),0) + COALESCE(LENGTH(clock),0)),0) AS source_bytes')->value('source_bytes');
                if ((int) $bytes > 1048576) {
                    throw new \RuntimeException('CBB EPA source exceeds its text-byte policy.');
                }
                $load = function () use ($query, $connection, $assertOwner) {
                    $columns = ObservedTerminalEpaContext::projection($connection, ObservedTerminalEpaContext::SOURCE_FIELDS);
                    $plays = $query()->select($columns)->orderBy('sequence_number')->orderBy('id')->limit(ObservedTerminalEpaContext::MAXIMUM_PLAYS + 1)->lockForUpdate()->get();
                    $assertOwner();
                    if ($plays->count() > ObservedTerminalEpaContext::MAXIMUM_PLAYS || $plays->contains(fn ($play) => (int) $play->source_oversized !== 0)) {
                        throw new \RuntimeException('CBB EPA current source exceeds its projection policy.');
                    }

                    return $plays;
                };
                $plays = $load();
                $useBaseline = (bool) config('epa.state_baseline.enabled', false);
                $loadBaseline = function () use ($game, $useBaseline, $connection, $assertOwner): array {
                    $assertOwner();
                    if (! $useBaseline) {
                        return [[], null];
                    }
                    $rows = $connection->table('epa_state_baselines')->useWritePdo()
                        ->select(ObservedTerminalEpaContext::projection($connection, ObservedTerminalEpaContext::BASELINE_FIELDS))
                        ->where('sport', 'cbb')->where('season', $game->season)->orderBy('id')->limit(4097)->lockForUpdate()->get();
                    $assertOwner();
                    if ($rows->count() > 4096) {
                        throw new \RuntimeException('CBB EPA baseline exceeds its row policy.');
                    }
                    $map = [];
                    $state = hash_init('sha256');
                    hash_update($state, ObservedTerminalEpaContext::POLICY."\0baseline-source-v1\0");
                    foreach ($rows as $row) {
                        if ((int) $row->source_oversized !== 0 || ! is_string($row->state_key)
                            || array_key_exists($row->state_key, $map) || ! is_numeric($row->expected_points) || ! is_finite((float) $row->expected_points)) {
                            throw new \RuntimeException('CBB EPA baseline source is unavailable.');
                        }
                        $map[$row->state_key] = (float) $row->expected_points;
                        $json = app(CanonicalPayloadHasher::class)->encode(array_intersect_key((array) $row, array_flip(ObservedTerminalEpaContext::BASELINE_FIELDS)));
                        hash_update($state, strlen($json).':'.$json);
                    }

                    return [$map, hash_final($state)];
                };
                [$baseline, $baselineSourceHash] = $loadBaseline();
                $terminal = ObservedTerminalEpaContext::capture($game, $plays, $baseline, $baselineSourceHash);
                $sourceHash = ObservedTerminalEpaContext::sourceHash($game, $plays, $baseline, $baselineSourceHash);
                $pinnedBaseline = $useBaseline ? new PinnedEpaBaseline((int) $game->id, (int) $game->season, $baseline, $baselineSourceHash) : null;
                $results = $this->calculator->calculateForGame($plays, (int) $game->home_team_id, (int) $game->away_team_id, (int) $game->season, 'cbb', $terminal, $pinnedBaseline);
                $timestamp = now()->toDateTimeString();
                $rows = [];
                $eligible = 0;
                foreach ($plays as $play) {
                    $result = $results[$play->id];
                    $eligible += $result['eligible'] ? 1 : 0;
                    $rows[] = [...array_intersect_key($play->getAttributes(), array_flip(ObservedTerminalEpaContext::SOURCE_FIELDS)),
                        'is_epa_eligible' => $result['eligible'],
                        'expected_points_before' => $result['ep_before'],
                        'expected_points_after' => $result['ep_after'],
                        'true_epa' => $result['epa'],
                        'epa_calculated_at' => $result['eligible'] && $result['epa'] === null ? null : $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
                if ($eligible === 0) {
                    throw new \RuntimeException('No attributable EPA plays after repair.');
                }

                [, $currentBaselineSourceHash] = $loadBaseline();
                if ($useBaseline !== (bool) config('epa.state_baseline.enabled', false)) {
                    throw new \RuntimeException('CBB EPA baseline policy changed during calculation.');
                }
                if (! hash_equals($sourceHash, ObservedTerminalEpaContext::sourceHash($loadGame(), $load(), $baseline, $currentBaselineSourceHash))) {
                    throw new \RuntimeException('CBB EPA source changed during calculation.');
                }
                $receipt = $ownedQuery(ModelRun::class)->create([
                    'id' => (string) Str::uuid(), 'sport' => 'cbb', 'run_type' => 'play_epa',
                    'model_version' => ObservedTerminalEpaContext::POLICY, 'feature_version' => 'observed-score-transitions-v1',
                    'blend_version' => 'none', 'config_hash' => app(CanonicalPayloadHasher::class)->hash(['policy' => ObservedTerminalEpaContext::POLICY, 'baseline' => $baseline, 'baseline_source_hash' => $baselineSourceHash]),
                    'code_version' => config('app.code_version'), 'status' => 'completed', 'started_at' => now(), 'completed_at' => now(),
                    'parameters' => ['policy' => ObservedTerminalEpaContext::POLICY],
                    'metadata' => ['source_hash' => $sourceHash, 'terminal_authorized' => $terminal !== null, 'game_id' => $game->id,
                        'terminal_play_id' => $terminal?->terminalPlayId, 'baseline_source_hash' => $baselineSourceHash, 'covered_play_ids' => $plays->modelKeys(),
                        'historical_availability_verified' => false],
                ]);

                $assertOwner();
                if (! $receipt->exists || ! $receipt->wasRecentlyCreated
                    || ! $ownedQuery(ModelRun::class)->useWritePdo()->whereKey($receipt->getKey())->exists()) {
                    throw new \RuntimeException('CBB EPA audit receipt was not persisted.');
                }
                foreach (array_chunk($rows, 250) as $chunk) {
                    $ownedQuery(Play::class)->upsert($chunk, ['id'], ['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa', 'epa_calculated_at', 'updated_at']);
                }

                $assertOwner();

                return $eligible;
            });
        } catch (\Throwable $exception) {
            // Only clean the original scope. A replacement PDO may belong to a different caller.
            if ($connection->getRawPdo() !== $pdo && $pdo->inTransaction()) {
                if ($entryLevel === 0) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec($connection->getQueryGrammar()->compileSavepointRollBack('trans'.($entryLevel + 1)));
                }
            }
            throw $exception;
        }
    }
}
