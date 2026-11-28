<?php

namespace App\Actions\MLB;

use App\Exceptions\MlbScoreReconciliationPersistenceException;
use App\Models\MLB\Game;
use App\Support\MLB\MlbGamePhase;
use Illuminate\Database\Connection;
use PDO;

class ReconcileGameScoreFromTeamStats
{
    private const HEADER = ['id', 'espn_event_id', 'season', 'season_type', 'status', 'home_team_id', 'away_team_id', 'home_score', 'away_score'];

    /**
     * @return array{game_id:int,status:string,home_score_before:int|null,away_score_before:int|null,home_score_after:int|null,away_score_after:int|null,source:string,reason:string,dry_run:bool,forced:bool}
     */
    public function execute(Game|int $game, bool $force = false, bool $dryRun = false): array
    {
        $input = $game instanceof Game ? $game : null;
        if ($input !== null && ($input->getTable() !== 'mlb_games' || $input->getKeyName() !== 'id'
            || ! $input->exists || $input->isDirty(self::HEADER))) {
            throw new MlbScoreReconciliationPersistenceException('The MLB game context changed before score reconciliation.');
        }
        $id = $input === null ? $game : $input->getRawOriginal('id');
        if (! is_scalar($id) || ($input !== null && (string) $input->getKey() !== (string) $id)) {
            throw new MlbScoreReconciliationPersistenceException('The MLB game identifier changed before score reconciliation.');
        }
        $connection = ($input ?? new Game)->getConnection();
        $connectionName = $connection->getName();
        $writePdo = $connection->getPdo();
        $inputHeader = $input === null ? null : array_intersect_key($input->getRawOriginal(), array_flip(self::HEADER));

        $result = $connection->transaction(function () use ($id, $connection, $connectionName, $writePdo, $inputHeader, $force, $dryRun): array {
            $model = (new Game)->setConnection($connectionName);
            $this->assertOwner($model, $connection, $writePdo);
            $current = $model->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail(self::HEADER);
            $this->assertOwner($current, $connection, $writePdo, $id);
            $header = array_intersect_key($current->getRawOriginal(), array_flip(self::HEADER));
            if ($current->isDirty(self::HEADER) || ($inputHeader !== null && ! $this->sameScalars($header, $inputHeader))) {
                throw new MlbScoreReconciliationPersistenceException('The current MLB game no longer matches the score reconciliation context.');
            }

            return $this->reconcileCurrent($current, $header, $connection, $writePdo, $force, $dryRun);
        });
        if ($input !== null && ! $dryRun && $result['status'] === 'updated') {
            // Preserve the supported caller-model replay behavior using only the verified score result.
            $input->forceFill(['home_score' => $result['home_score_after'], 'away_score' => $result['away_score_after']]);
            $input->syncOriginalAttributes(['home_score', 'away_score']);
        }

        return $result;
    }

    private function reconcileCurrent(Game $game, array $header, Connection $connection, PDO $writePdo, bool $force, bool $dryRun): array
    {
        $id = $header['id'];
        $homeScoreBefore = $this->nullableInt($game->home_score);
        $awayScoreBefore = $this->nullableInt($game->away_score);
        $base = [
            'game_id' => (int) $id,
            'home_score_before' => $homeScoreBefore,
            'away_score_before' => $awayScoreBefore,
            'home_score_after' => $homeScoreBefore,
            'away_score_after' => $awayScoreBefore,
            'source' => 'mlb_team_stats.runs',
            'dry_run' => $dryRun,
            'forced' => $force,
        ];
        if (! MlbGamePhase::isFinal($game)) {
            return [...$base, 'status' => 'skipped', 'reason' => 'game_not_final'];
        }

        $homeSource = $this->sourceRow($connection, $id, $header['home_team_id'], 'home');
        $awaySource = $this->sourceRow($connection, $id, $header['away_team_id'], 'away');
        $homeRuns = $this->nullableInt($homeSource['runs'] ?? null);
        $awayRuns = $this->nullableInt($awaySource['runs'] ?? null);
        if ($homeRuns === null || $awayRuns === null) {
            return [...$base, 'status' => 'skipped', 'reason' => 'missing_team_stats_runs'];
        }
        $homeConflicts = $homeScoreBefore !== null && $homeScoreBefore !== $homeRuns;
        $awayConflicts = $awayScoreBefore !== null && $awayScoreBefore !== $awayRuns;
        if (($homeConflicts || $awayConflicts) && ! $force) {
            return [...$base, 'status' => 'conflict', 'home_score_after' => $homeRuns, 'away_score_after' => $awayRuns,
                'reason' => 'game_score_conflicts_with_team_stats_runs'];
        }
        if ($homeScoreBefore === $homeRuns && $awayScoreBefore === $awayRuns) {
            return [...$base, 'status' => 'unchanged', 'reason' => 'scores_already_match_team_stats_runs'];
        }
        if (! $dryRun) {
            $expected = [...$header, 'home_score' => $homeRuns, 'away_score' => $awayRuns];
            $this->assertOwner($game, $connection, $writePdo, $id);
            if ($game->forceFill(['home_score' => $homeRuns, 'away_score' => $awayRuns])->save() !== true) {
                throw new MlbScoreReconciliationPersistenceException('The reconciled MLB final scores could not be saved.');
            }
            $this->assertOwner($game, $connection, $writePdo, $id);
            $actual = $connection->table('mlb_games')->useWritePdo()->where('id', $id)->first(self::HEADER);
            if ($actual === null || ! $this->sameScalars((array) $actual, $expected)) {
                throw new MlbScoreReconciliationPersistenceException('The reconciled MLB final-score row changed before acknowledgement.');
            }
            foreach ([$homeSource, $awaySource] as $source) {
                $actualSource = $connection->table('mlb_team_stats')->useWritePdo()->where('id', $source['id'])
                    ->first(['id', 'game_id', 'team_id', 'team_type', 'runs']);
                if ($actualSource === null || ! $this->sameScalars((array) $actualSource, $source)) {
                    throw new MlbScoreReconciliationPersistenceException('The MLB team-stat run source changed before score acknowledgement.');
                }
            }
            $this->assertOwner($game, $connection, $writePdo, $id);
        }

        return [...$base, 'status' => 'updated', 'home_score_after' => $homeRuns, 'away_score_after' => $awayRuns,
            'reason' => $force && ($homeConflicts || $awayConflicts)
                ? 'force_reconciled_score_from_team_stats' : 'filled_missing_final_score_from_team_stats'];
    }

    private function sourceRow(Connection $connection, mixed $gameId, mixed $teamId, string $teamType): ?array
    {
        $row = $connection->table('mlb_team_stats')->useWritePdo()->where('game_id', $gameId)
            ->where('team_id', $teamId)->where('team_type', $teamType)->lockForUpdate()
            ->first(['id', 'game_id', 'team_id', 'team_type', 'runs']);

        return $row === null ? null : (array) $row;
    }

    private function assertOwner(Game $game, Connection $connection, PDO $writePdo, mixed $id = null): void
    {
        if ($game->getTable() !== 'mlb_games' || $game->getKeyName() !== 'id'
            || $game->getConnection() !== $connection || $connection->getPdo() !== $writePdo
            || ($id !== null && (string) $game->getKey() !== (string) $id)) {
            throw new MlbScoreReconciliationPersistenceException('The MLB score reconciliation owner or write connection changed.');
        }
    }

    private function sameScalars(array $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $actual)
                || (($value === null || $actual[$field] === null)
                    ? $actual[$field] !== $value : (string) $actual[$field] !== (string) $value)) {
                return false;
            }
        }

        return true;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
