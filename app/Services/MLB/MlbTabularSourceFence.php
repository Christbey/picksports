<?php

namespace App\Services\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\ModelRun;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use Illuminate\Database\Connection;
use RuntimeException;
use WeakMap;
use WeakReference;

/** Owns source-first SQL only. The callback must perform local DB work and no file/provider/process IO. */
final class MlbTabularSourceFence
{
    private static ?WeakMap $owners = null;

    public function __construct(private readonly MlbTabularSnapshotReader $reader, private readonly MlbTabularDatasetAdmission $admission, private readonly MlbTabularDatasetExporter $exporter, private readonly MlbTabularDatasetProjector $projector) {}

    public function run(array $proof, callable $commit): mixed
    {
        return $this->runOwned($proof, $commit);
    }

    /** Predetermined target only: Event precedes the sorted union of source and target Games. */
    public function runForInferenceTarget(array $proof, int $gameId, ?int $eventId, callable $commit): mixed
    {
        if ($gameId < 1 || ($eventId !== null && $eventId < 1)) {
            throw new RuntimeException('Inference target identity is invalid.');
        }

        return $this->runOwned($proof, $commit, $gameId, $eventId);
    }

    private function runOwned(array $proof, callable $commit, ?int $gameId = null, ?int $eventId = null): mixed
    {
        $this->admission->assertProof($proof);
        $proofHash = self::proofHash($proof);
        $connection = $this->reader->connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('MLB tabular mutation must enter its own source-first transaction; unknown inherited lock order is unsupported.');
        }

        // Every source selector is explicitly primary-only; the unused replica is not admitted or resolved.
        $clock = app(EloSqlClockPolicy::class);
        $token = $clock->capturePrimary($connection);

        return $connection->transaction(function () use ($proof, $proofHash, $commit, $clock, $token, $connection, $gameId, $eventId): mixed {
            $refs = $proof['references'];
            $games = $this->ids($refs, 'game_id');
            $predictions = $this->ids($refs, 'prediction_id');
            $snapshots = $this->ids($refs, 'snapshot_id');
            $evaluations = $this->ids($refs, 'evaluation_id');
            $runs = $this->ids($refs, 'model_run_id');
            $quotes = $this->ids($refs, 'quote_id');
            if ($gameId !== null) {
                if ((new SportEvent)->getConnection() !== $connection || (new Game)->getConnection() !== $connection) {
                    throw new RuntimeException('Inference target models changed their resolved primary connection.');
                }
                $clock->assertUnchanged($token, $connection);
                if ($eventId !== null) {
                    $sportBytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(sport)' : 'length(CAST(sport AS BLOB))';
                    $event = SportEvent::query()->useWritePdo()->select('id')
                        ->selectRaw('CASE WHEN '.$sportBytes.' <= 16 THEN sport ELSE NULL END AS sport')
                        ->whereKey($eventId)->lockForUpdate()->first();
                    if ($event === null || $event->sport !== 'mlb') {
                        throw new RuntimeException('Predetermined MLB inference event disappeared or changed sport.');
                    }
                    // This is the first consistent user-table read in our fresh
                    // standalone transaction, while Event is already locked.
                    // It acquires no Game lock before the sorted union below.
                    $members = Game::query()->useWritePdo()->select('id')->where('sport_event_id', $eventId)
                        ->orderBy('id')->limit(2)->pluck('id')->all();
                    if (count($members) !== 1 || (int) $members[0] !== $gameId) {
                        throw new RuntimeException('Current inference event changed its unique predetermined Game membership.');
                    }
                }
                $games = array_values(array_unique([...$games, $gameId]));
                sort($games, SORT_NUMERIC);
            }
            $this->lock(Game::query(), $games);
            if ($gameId !== null) {
                $ownerBytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(sport_event_id)' : 'length(CAST(sport_event_id AS BLOB))';
                $target = Game::query()->useWritePdo()->select('id')
                    ->selectRaw('CASE WHEN COALESCE('.$ownerBytes.',0) <= 20 THEN sport_event_id ELSE NULL END AS sport_event_id')
                    ->selectRaw('CASE WHEN COALESCE('.$ownerBytes.',0) <= 20 THEN 0 ELSE 1 END AS owner_oversized')
                    ->whereKey($gameId)->lockForUpdate()->first();
                if ($target === null || (int) $target->owner_oversized !== 0 || ($target->sport_event_id === null ? null : (int) $target->sport_event_id) !== $eventId) {
                    throw new RuntimeException('Inference target event owner changed; no replacement parent is locked.');
                }
            }
            $lockedPredictions = Prediction::query()->select(['id', 'game_id'])->whereKey($predictions)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedSnapshots = PredictionFeatureSnapshot::query()->select(['id', 'game_id', 'prediction_id', 'prediction_table', 'model_run_id'])
                ->whereKey($snapshots)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($lockedPredictions->count() !== count($predictions) || $lockedSnapshots->count() !== count($snapshots)) {
                throw new RuntimeException('MLB source identity disappeared before commit.');
            }
            foreach ($refs as $ref) {
                $snapshot = $lockedSnapshots[$ref['snapshot_id']] ?? null;
                $prediction = $lockedPredictions[$ref['prediction_id']] ?? null;
                if ($snapshot === null || $prediction === null || $snapshot->prediction_table !== 'mlb_predictions'
                    || (int) $snapshot->prediction_id !== $ref['prediction_id'] || (int) $snapshot->game_id !== $ref['game_id']
                    || (int) $prediction->game_id !== $ref['game_id'] || $snapshot->model_run_id !== $ref['model_run_id']) {
                    throw new RuntimeException('MLB current source owner changed before commit; no new owner is locked.');
                }
            }
            $this->lock(PredictionEvaluation::query(), $evaluations);
            $sourceRuns = ModelRun::query()->select(['id', 'sport', 'run_type'])->whereKey($runs)->orderBy('id')->lockForUpdate()->get();
            if ($sourceRuns->count() !== count($runs) || $sourceRuns->contains(fn ($run) => $run->sport !== 'mlb' || in_array($run->run_type, ['training', 'weekly_training_cycle'], true))) {
                throw new RuntimeException('MLB capture source run is missing, foreign or conflicts with target training ownership.');
            }
            $this->lock(GameOddsSnapshot::query(), $quotes);
            $bytes = $tokens = $index = 0;
            foreach ($this->exporter->rows($this->reader->query($proof['seasons']), $refs, $proof['include_metadata'], false, $bytes, $tokens, true) as [$row, $current]) {
                if ($row === null || $this->projector->stableHash($refs[$index] ?? null) !== $this->projector->stableHash($current)) {
                    throw new RuntimeException('MLB source/target/capture availability changed at the mutation boundary.');
                }
                $index++;
            }
            if ($index !== $proof['rows']) {
                throw new RuntimeException('MLB current source set is incomplete.');
            }

            $clock->assertUnchanged($token, $this->reader->connection());
            $transactions = app(MlbArtifactCohortSerializer::class);
            $witness = $transactions->transactionWitness($connection);
            self::$owners ??= new WeakMap;
            self::$owners[$connection] = ['proof' => $proofHash, 'pdo' => WeakReference::create($connection->getPdo()),
                'transactions' => $transactions, 'witness' => $witness, 'clock' => $clock, 'token' => $token];
            try {
                $result = $commit();
                self::assertOwned($this->reader->connection(), $proof);

                return $result;
            } finally {
                unset(self::$owners[$connection]);
            }
        });
    }

    public static function assertOwned(Connection $connection, array $proof): void
    {
        $owner = self::$owners[$connection] ?? null;
        if ($owner === null) {
            throw new RuntimeException('MLB prepared persistence requires its matching active source-first fence.');
        }
        // A handle replacement can reset Laravel's transaction counter; preserve its clock/handle refusal first.
        $owner['clock']->assertUnchanged($owner['token'], $connection);
        if ($connection->transactionLevel() < 1 || ! hash_equals($owner['proof'], self::proofHash($proof))) {
            throw new RuntimeException('MLB prepared persistence requires its matching active source-first fence.');
        }
        if ($owner['pdo']->get() !== $connection->getPdo()
            || $owner['transactions']->transactionWitness($connection) !== $owner['witness']) {
            throw new RuntimeException('MLB source transaction or write PDO changed before dependent persistence.');
        }
    }

    private static function proofHash(array $proof): string
    {
        // Bound even unexpected extra fields before canonical hashing; nominal row limits alone are insufficient.
        MlbTabularJsonBudget::encode($proof, 2097152, 100000, 24);

        return app(MlbTabularDatasetProjector::class)->stableHash($proof);
    }

    private function ids(array $refs, string $column): array
    {
        $ids = array_values(array_unique(array_filter(array_column($refs, $column), fn ($id) => $id !== null)));
        sort($ids);

        return $ids;
    }

    private function lock($query, array $ids): void
    {
        if ($ids !== [] && $query->select('id')->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->count() !== count($ids)) {
            throw new RuntimeException('MLB required source row disappeared before commit.');
        }
    }
}
