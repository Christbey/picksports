<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use Illuminate\Database\Connection;
use RuntimeException;
use WeakMap;

/** Qualified tabular mutations only. Period provenance and the final inference target fence are separate. */
final class MlbExistingArtifactMutation
{
    private WeakMap $prepared;

    public function __construct(
        private readonly MlbTabularArtifactAdmission $artifacts,
        private readonly MlbTabularSourceFence $sources,
        private readonly MlbArtifactCohortSerializer $cohorts,
        private readonly MlbArtifactCohortState $state,
        private readonly EloSqlClockPolicy $clocks,
    ) {
        $this->prepared = new WeakMap;
    }

    public function activate(ModelArtifact $artifact, array $metadata = []): void
    {
        $this->commit($this->prepareSelection($artifact, 'activate', ['metadata' => $metadata]));
        $artifact->refresh();
    }

    public function deactivate(ModelArtifact $artifact, string $reason): void
    {
        $this->commit($this->prepareSelection($artifact, 'deactivate', ['reason' => trim($reason) !== '' ? trim($reason) : 'deactivated']));
        $artifact->refresh();
    }

    public function prepareSelection(ModelArtifact $artifact, string $action, array $payload): PreparedMlbArtifactMutation
    {
        $connection = (new ModelArtifact)->getConnection();
        if ($connection->transactionLevel() !== 0 || $artifact->getConnection() !== $connection || ! $artifact->exists
            || $artifact->isDirty() || ! in_array($action, ['activate', 'deactivate'], true)) {
            throw new RuntimeException('MLB existing artifact mutation requires a clean standalone current identity.');
        }
        $this->cohorts->assertSupported($connection);
        // No caller-supplied sport/family predicate or callback can grant source or range authority.
        $clock = $this->clocks->capturePrimary($connection);
        $initial = $this->artifacts->admit($artifact);
        $this->clocks->assertUnchanged($clock, $connection);
        if ($initial['artifact']->model_type !== 'mlb_tabular_bundle') {
            throw new RuntimeException('This MLB mutation entry requires qualified tabular provenance.');
        }
        $expectedKeys = $action === 'activate' ? ['metadata'] : ['reason'];
        if (array_keys($payload) !== $expectedKeys
            || ($action === 'activate' && ! is_array($payload['metadata'] ?? null))
            || ($action === 'deactivate' && (! is_string($payload['reason'] ?? null) || trim($payload['reason']) === ''))) {
            throw new RuntimeException('MLB selection action requires its exact bounded payload.');
        }
        MlbTabularJsonBudget::encode($payload, 65536, 10000, 12);
        $prepared = new PreparedMlbArtifactMutation((string) $initial['artifact']->id,
            (string) $initial['artifact']->training_run_id, $initial['fingerprint'], $action, $payload);
        $this->prepared[$prepared] = ['admission' => $initial, 'connection' => $connection, 'clock' => $clock];

        return $prepared;
    }

    public function commit(PreparedMlbArtifactMutation $prepared): void
    {
        $ownership = $this->prepared[$prepared] ?? null;
        $initial = $ownership['admission'] ?? null;
        if ($initial === null || $prepared->artifactId !== $initial['artifact']->id
            || $prepared->trainingRunId !== $initial['artifact']->training_run_id
            || ! hash_equals($prepared->fingerprint, $initial['fingerprint'])) {
            throw new RuntimeException('MLB mutation requires its exact owned prepared context.');
        }
        $connection = (new ModelRun)->getConnection();
        if ($connection !== $ownership['connection'] || $initial['artifact']->getConnection() !== $connection) {
            throw new RuntimeException('MLB mutation cannot switch its resolved source and target connection.');
        }
        $this->clocks->assertUnchanged($ownership['clock'], $connection);
        $proof = $initial['proof']['dataset'];
        $this->sources->run($proof, function () use ($prepared, $initial, $proof, $ownership): void {
            $connection = (new ModelRun)->getConnection();
            $this->clocks->assertUnchanged($ownership['clock'], $connection);
            $this->cohorts->run($connection, function () use ($prepared, $initial, $proof, $connection): void {
                $members = $this->state->read($connection);
                $selected = $members->firstWhere('id', $prepared->artifactId);
                if ($selected === null || $selected->training_run_id !== $prepared->trainingRunId
                    || $selected->model_type !== 'mlb_tabular_bundle') {
                    throw new RuntimeException('MLB existing target changed its cohort or training owner.');
                }
                $current = $this->artifacts->currentForOwnedMutation($initial['artifact'], $initial);
                $artifact = $current['artifact'];
                if ($prepared->action === 'activate' && ! in_array($artifact->status, ['challenger', 'promotion_eligible'], true)) {
                    throw new RuntimeException('Only current challengers may change shadow selection.');
                }
                $peerIds = $prepared->action === 'activate' ? $this->state->shadowSelectionPeerIds($connection, $members) : [];
                $replacements = [];
                foreach ($members as $member) {
                    $isTarget = $member->id === $artifact->id;
                    if (! $isTarget && ($prepared->action !== 'activate' || ! isset($peerIds[$member->id]) || data_get($member->metrics, 'shadow_selection.active') !== true)) {
                        continue;
                    }
                    $selection = $isTarget && $prepared->action === 'activate'
                        ? ['active' => true, 'activated_at' => now()->toIso8601String(), 'deactivated_at' => null,
                            'deactivation_reason' => null, 'metadata' => $prepared->payload['metadata']]
                        : ['active' => false, 'deactivated_at' => now()->toIso8601String(),
                            'deactivation_reason' => $isTarget ? $prepared->payload['reason'] : 'superseded_by_'.$artifact->id];
                    $metrics = (array) $member->metrics;
                    $metrics['shadow_selection'] = [...(array) ($metrics['shadow_selection'] ?? []), ...$selection];
                    $replacements[$member->id] = $metrics;
                }
                $this->state->assertReplacements($members, $replacements);
                // Peer deactivation uses only the already locked scalar cohort. No other training run is acquired.
                foreach ($members as $member) {
                    if (! array_key_exists($member->id, $replacements)) {
                        continue;
                    }
                    $this->assertOwned($connection, $proof);
                    if ($member->forceFill(['metrics' => $replacements[$member->id]])->save() !== true) {
                        throw new RuntimeException('MLB shadow selection persistence was cancelled.');
                    }
                }
                $this->assertOwned($connection, $proof);
            });
        });
    }

    private function assertOwned(Connection $connection, array $proof): void
    {
        MlbTabularSourceFence::assertOwned($connection, $proof);
        MlbArtifactCohortSerializer::assertOwned($connection);
    }
}
