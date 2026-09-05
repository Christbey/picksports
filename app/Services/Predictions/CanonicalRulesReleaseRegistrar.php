<?php

namespace App\Services\Predictions;

use App\Contracts\Predictions\CanonicalReleaseDefinition;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use Carbon\CarbonImmutable;

class CanonicalRulesReleaseRegistrar
{
    public function __construct(
        private readonly CanonicalPayloadHasher $hasher,
        private readonly CalculationReleaseManager $manager,
        private readonly ModelRunRecorder $modelRunRecorder,
    ) {}

    /**
     * @param  class-string  $calculatorClass
     * @param  class-string  $snapshotBuilderClass
     */
    public function register(
        CanonicalReleaseDefinition $releaseDefinition,
        string $calculatorClass,
        string $snapshotBuilderClass,
        ?string $semanticVersion = null,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'Canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        $sport = $releaseDefinition->sport();
        $semanticVersion = trim($semanticVersion ?: $releaseDefinition->semanticVersion());
        $configuration = $releaseDefinition->configuration();
        $attributes = [
            'sport' => $sport,
            'phase' => 'pregame',
            'calculator_name' => $releaseDefinition->calculatorName(),
            'semantic_version' => $semanticVersion,
        ];
        $definition = [
            'release_type' => 'rules',
            'code_revision' => $this->codeRevision($releaseDefinition, $calculatorClass, $snapshotBuilderClass),
            'configuration_hash' => $this->hasher->hash($configuration),
            'input_schema_version' => $releaseDefinition->inputSchemaVersion(),
            'configuration' => $configuration,
            'metadata' => [
                'calculator_class' => $calculatorClass,
                'snapshot_builder_class' => $snapshotBuilderClass,
            ],
        ];

        $release = CalculationRelease::query()->firstOrCreate($attributes, [
            ...$definition,
            'status' => 'draft',
        ]);

        foreach (array_diff_key($definition, array_flip(['configuration', 'metadata'])) as $field => $expected) {
            if ($release->{$field} !== $expected) {
                throw new PredictionLifecycleException(
                    'Existing '.strtoupper($sport)." release {$semanticVersion} has conflicting {$field} evidence.",
                );
            }
        }

        if (! hash_equals($definition['configuration_hash'], $this->hasher->hash($release->configuration ?? []))) {
            throw new PredictionLifecycleException(
                'Existing '.strtoupper($sport)." release {$semanticVersion} has conflicting frozen configuration evidence.",
            );
        }

        if (! $approve || $release->status !== 'draft') {
            return $release;
        }

        return $this->manager->approve($release, $actor, $reason, $effectiveAt);
    }

    /** @param class-string $calculatorClass @param class-string $snapshotBuilderClass */
    private function codeRevision(
        CanonicalReleaseDefinition $definition,
        string $calculatorClass,
        string $snapshotBuilderClass,
    ): string {
        $revision = $this->modelRunRecorder->codeVersion();

        if (filled($revision)) {
            return $revision;
        }

        $classes = [
            self::class,
            $definition::class,
            $calculatorClass,
            $snapshotBuilderClass,
        ];

        foreach ([$definition::class, $calculatorClass, $snapshotBuilderClass] as $class) {
            $parent = get_parent_class($class);

            while (is_string($parent)) {
                $classes[] = $parent;
                $parent = get_parent_class($parent);
            }
        }

        $classes = array_values(array_unique($classes));

        return hash('sha256', implode('', array_map(function (string $class): string {
            $file = (new \ReflectionClass($class))->getFileName();

            return is_string($file) && is_file($file) ? (string) file_get_contents($file) : '';
        }, $classes)));
    }
}
