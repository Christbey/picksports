<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Services\MLB\MlbExistingArtifactMutation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ShadowArtifactSelector
{
    /**
     * Return the database-selected challenger for one sport and model family.
     */
    public function activeChallenger(string $sport, string $modelType): ?ModelArtifact
    {
        return ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', strtolower(trim($sport)))
            ->where('model_type', $modelType)
            ->whereIn('status', ['challenger', 'promotion_eligible'])
            ->latest('updated_at')
            ->latest('created_at')
            ->get()
            ->first(
                fn (ModelArtifact $artifact): bool => data_get(
                    $artifact->metrics,
                    'shadow_selection.active',
                ) === true,
            );
    }

    /**
     * Select one active challenger per sport and model family.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function activateChallenger(ModelArtifact $artifact, array $metadata = []): void
    {
        $identity = $this->currentIdentity($artifact);
        if ($identity['_mlb_cohort'] || strtolower(trim($identity['sport'])) === 'mlb') {
            app(MlbExistingArtifactMutation::class)->activate($artifact, $metadata);

            return;
        }
        $this->genericSelection($artifact, $identity, true, $metadata, '');
    }

    public function deactivateChallenger(ModelArtifact $artifact, string $reason): void
    {
        $identity = $this->currentIdentity($artifact);
        if ($identity['_mlb_cohort'] || strtolower(trim($identity['sport'])) === 'mlb') {
            app(MlbExistingArtifactMutation::class)->deactivate($artifact, $reason);

            return;
        }
        $this->genericSelection($artifact, $identity, false, [], $reason);
    }

    /** Only bounded persisted identity decides the mutation path, never a caller's edited fields. */
    private function currentIdentity(ModelArtifact $artifact): array
    {
        if (! $artifact->exists || $artifact->isDirty() || ! Str::isUuid((string) $artifact->getRawOriginal($artifact->getKeyName()))) {
            throw new \RuntimeException('Shadow selection requires a clean persisted identity.');
        }
        $connection = $artifact->getConnection();
        $grammar = $connection->getQueryGrammar();
        $mysql = $connection->getDriverName() === 'mysql';
        if (! $mysql && $connection->getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Shadow selection identity projection is unsupported.');
        }
        $length = $mysql ? 'OCTET_LENGTH' : 'length';
        $cast = $mysql ? 'BINARY' : 'BLOB';
        $select = [];
        foreach (['id' => 36, 'sport' => 32, 'model_type' => 96, 'training_run_id' => 36, 'status' => 32] as $field => $maximum) {
            $column = $grammar->wrap($field);
            $select[] = "CASE WHEN $length(CAST($column AS $cast)) <= $maximum THEN $column ELSE NULL END AS $column";
        }
        $row = $connection->table('model_artifacts')->useWritePdo()->where('id', $artifact->getRawOriginal($artifact->getKeyName()))->selectRaw(implode(', ', $select).', CASE WHEN sport = ? THEN 1 ELSE 0 END AS _mlb_cohort', ['mlb'])->first();
        if ($row === null) {
            throw new \RuntimeException('Shadow selection target no longer exists.');
        }
        $inMlbCohort = $row->_mlb_cohort;
        unset($row->_mlb_cohort);
        if (! in_array($inMlbCohort, [0, 1, '0', '1'], true)) {
            throw new \RuntimeException('Shadow selection cohort classification is unavailable.');
        }
        foreach ((array) $row as $value) {
            if (! is_string($value) || $value === '') {
                throw new \RuntimeException('Shadow selection current identity is unavailable.');
            }
        }

        return [...(array) $row, '_mlb_cohort' => (int) $inMlbCohort === 1];
    }

    private function genericSelection(ModelArtifact $input, array $identity, bool $activate, array $metadata, string $reason): void
    {
        $connection = $input->getConnection();
        $connection->transaction(function () use ($input, $identity, $activate, $metadata, $reason, $connection): void {
            $query = $input->newModelQuery()->useWritePdo();
            if ($query->getQuery()->getConnection() !== $connection) {
                throw new \RuntimeException('Shadow selection connection changed.');
            }
            $candidates = $query->where('sport', $identity['sport'])->where('model_type', $identity['model_type'])
                ->lockForUpdate()->get();
            $selected = $candidates->firstWhere('id', $identity['id']);
            if ($selected === null) {
                throw new \RuntimeException('Shadow selection target left its prepared cohort.');
            }
            foreach (array_diff_key($identity, ['_mlb_cohort' => true]) as $field => $value) {
                if ($selected->getRawOriginal($field) !== $value) {
                    throw new \RuntimeException('Shadow selection current target identity changed.');
                }
            }
            if ($activate && ! in_array($selected->status, ['challenger', 'promotion_eligible'], true)) {
                throw new \RuntimeException('Only current challengers may be activated for shadow inference.');
            }
            if ($activate) {
                foreach ($candidates as $candidate) {
                    if ($candidate->is($selected) || ! in_array($candidate->status, ['challenger', 'promotion_eligible'], true)
                        || data_get($candidate->metrics, 'shadow_selection.active') !== true) {
                        continue;
                    }
                    $this->writeSelection($candidate, ['active' => false, 'deactivated_at' => now()->toIso8601String(),
                        'deactivation_reason' => 'superseded_by_'.$selected->id]);
                }
            }
            $this->writeSelection($selected, $activate
                ? ['active' => true, 'activated_at' => now()->toIso8601String(), 'deactivated_at' => null,
                    'deactivation_reason' => null, 'metadata' => $metadata]
                : ['active' => false, 'deactivated_at' => now()->toIso8601String(),
                    'deactivation_reason' => trim($reason) !== '' ? trim($reason) : 'deactivated']);
        });
        $input->refresh();
    }

    /**
     * Return an explicit pin, or the active challenger and latest promoted champion.
     *
     * @return Collection<int, ModelArtifact>
     */
    public function inferenceCohort(
        string $sport,
        string $modelType,
        ?string $pinnedArtifactId = null,
    ): Collection {
        $sport = strtolower(trim($sport));
        $pinnedArtifactId = trim((string) $pinnedArtifactId);

        if ($pinnedArtifactId !== '') {
            return ModelArtifact::query()
                ->with('trainingRun')
                ->whereKey($pinnedArtifactId)
                ->where('sport', $sport)
                ->where('model_type', $modelType)
                ->whereIn('status', ['challenger', 'promotion_eligible', 'promoted'])
                ->get();
        }

        $cohort = new Collection;
        $challenger = $this->activeChallenger($sport, $modelType);
        if ($challenger !== null) {
            $cohort->push($challenger);
        }

        $champion = ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', $sport)
            ->where('model_type', $modelType)
            ->where('status', 'promoted')
            ->latest('promoted_at')
            ->latest('created_at')
            ->first();
        if ($champion !== null && ! $cohort->contains('id', $champion->id)) {
            $cohort->push($champion);
        }

        return $cohort->values();
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function writeSelection(ModelArtifact $artifact, array $selection): void
    {
        $metrics = (array) $artifact->metrics;
        $metrics['shadow_selection'] = [
            ...(array) data_get($metrics, 'shadow_selection', []),
            ...$selection,
        ];

        if ($artifact->forceFill(['metrics' => $metrics])->save() !== true) {
            throw new \RuntimeException('Shadow selection persistence was cancelled.');
        }
    }
}
