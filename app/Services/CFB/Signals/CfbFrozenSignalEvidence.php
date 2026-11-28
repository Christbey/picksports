<?php

namespace App\Services\CFB\Signals;

use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Opt-in immutable learned evidence for an explicitly reviewed release; never trains or activates a release. */
class CfbFrozenSignalEvidence
{
    public const SCHEMA = 'cfb-frozen-signal-evidence-v1';

    public function freeze(array $evidence, array $configuration, CarbonImmutable $availableAt, string $sourcePredictionId): array
    {
        if (trim($sourcePredictionId) === '') {
            throw new InvalidArgumentException('A source prediction identity is required.');
        }
        $this->validate($evidence, $configuration, $availableAt);
        $artifact = ['schema' => self::SCHEMA, 'available_at' => $availableAt->toIso8601String(),
            'source_prediction_id' => $sourcePredictionId, 'evidence' => $evidence,
            'signal_configuration_hash' => $this->signalConfigurationHash($configuration),
            'promotion' => 'candidate_only_no_automatic_release_activation'];

        return [...$artifact, 'content_hash' => app(CanonicalPayloadHasher::class)->hash($artifact)];
    }

    public function resolve(array $configuration, CarbonImmutable $capturedAt): ?array
    {
        $artifact = data_get($configuration, 'football_signals.frozen_evidence');
        if ($artifact === null) {
            return null;
        }
        if (! is_array($artifact) || ($artifact['schema'] ?? null) !== self::SCHEMA
            || ! is_string($artifact['content_hash'] ?? null) || ! is_array($artifact['evidence'] ?? null)
            || ! is_string($artifact['available_at'] ?? null) || ! is_string($artifact['source_prediction_id'] ?? null)
            || ($artifact['signal_configuration_hash'] ?? null) !== $this->signalConfigurationHash($configuration)
            || ! hash_equals($artifact['content_hash'], app(CanonicalPayloadHasher::class)->hash(array_diff_key($artifact, ['content_hash' => true])))) {
            throw new InvalidArgumentException('Pinned CFB signal evidence is malformed or its content hash changed.');
        }
        $availableAt = CarbonImmutable::parse($artifact['available_at']);
        if ($availableAt->gt($capturedAt)) {
            throw new InvalidArgumentException('Pinned CFB signal evidence was unavailable at snapshot capture.');
        }
        $this->validate($artifact['evidence'], $configuration, $availableAt);

        return [...$artifact['evidence'], 'frozen_artifact' => ['content_hash' => $artifact['content_hash'],
            'available_at' => $artifact['available_at'], 'source_prediction_id' => $artifact['source_prediction_id']]];
    }

    public function assertSnapshotMatches(array $inputs, array $configuration, CarbonImmutable $capturedAt): void
    {
        $expected = $this->resolve($configuration, $capturedAt);
        if ($expected === null) {
            return;
        }
        $actual = $inputs['football_signal_evidence'] ?? null;
        $hasher = app(CanonicalPayloadHasher::class);
        if (! is_array($actual) || ! hash_equals($hasher->hash($expected), $hasher->hash($actual))) {
            throw new InvalidArgumentException('CFB snapshot signal evidence does not match the pinned release artifact.');
        }
    }

    private function signalConfigurationHash(array $configuration): string
    {
        return app(CanonicalPayloadHasher::class)->hash(array_diff_key($configuration['football_signals'] ?? [], ['frozen_evidence' => true]));
    }

    private function validate(array $evidence, array $configuration, CarbonImmutable $availableAt): void
    {
        $catalog = data_get($configuration, 'football_signals.catalog', CfbFootballSignalCatalog::all());
        if (($evidence['version'] ?? null) !== CfbFootballSignalModel::VERSION
            || ($evidence['baseline_hash'] ?? null) !== CfbFootballSignalEvidence::baselineHash($configuration)
            || ($evidence['catalog_hash'] ?? null) !== CfbFootballSignalEvidence::catalogHash($catalog)
            || ($evidence['feature_policy'] ?? 'observed_only') !== data_get($configuration, 'football_signals.feature_policy', 'observed_only')
            || ($evidence['training_policy'] ?? 'same_release_frozen_predictions') !== data_get($configuration, 'football_signals.training_policy', 'same_release_frozen_predictions')
            || ! is_array($evidence['signals'] ?? null) || ! is_string($evidence['as_of'] ?? null)
            || (data_get($configuration, 'football_signals.weighting') === 'joint_ridge_v1' && ! is_array($evidence['joint_models'] ?? null))) {
            throw new InvalidArgumentException('Pinned CFB signal evidence does not match the release baseline, catalog, or feature/training policy.');
        }
        $asOf = CarbonImmutable::parse($evidence['as_of']);
        $latest = $evidence['latest_source_observed_at'] ?? null;
        if ($asOf->gt($availableAt) || ($latest !== null && CarbonImmutable::parse($latest)->gt($asOf))) {
            throw new InvalidArgumentException('Pinned CFB signal evidence contains future training or source observations.');
        }
    }
}
