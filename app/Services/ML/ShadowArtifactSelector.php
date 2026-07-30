<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        if (! in_array($artifact->status, ['challenger', 'promotion_eligible'], true)) {
            throw new \InvalidArgumentException('Only challenger artifacts may be activated for shadow inference.');
        }

        DB::transaction(function () use ($artifact, $metadata): void {
            $candidates = ModelArtifact::query()
                ->where('sport', $artifact->sport)
                ->where('model_type', $artifact->model_type)
                ->whereIn('status', ['challenger', 'promotion_eligible'])
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->is($artifact)
                    || data_get($candidate->metrics, 'shadow_selection.active') !== true) {
                    continue;
                }

                $this->writeSelection($candidate, [
                    'active' => false,
                    'deactivated_at' => now()->toIso8601String(),
                    'deactivation_reason' => 'superseded_by_'.$artifact->id,
                ]);
            }

            $selected = $candidates->firstWhere($artifact->getKeyName(), $artifact->getKey())
                ?? $artifact->newQuery()->lockForUpdate()->findOrFail($artifact->getKey());

            $this->writeSelection($selected, [
                'active' => true,
                'activated_at' => now()->toIso8601String(),
                'deactivated_at' => null,
                'deactivation_reason' => null,
                'metadata' => $metadata,
            ]);
        });

        $artifact->refresh();
    }

    public function deactivateChallenger(ModelArtifact $artifact, string $reason): void
    {
        DB::transaction(function () use ($artifact, $reason): void {
            $selected = $artifact->newQuery()
                ->lockForUpdate()
                ->findOrFail($artifact->getKey());

            $this->writeSelection($selected, [
                'active' => false,
                'deactivated_at' => now()->toIso8601String(),
                'deactivation_reason' => trim($reason) !== '' ? trim($reason) : 'deactivated',
            ]);
        });

        $artifact->refresh();
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

        $artifact->forceFill(['metrics' => $metrics])->save();
    }
}
