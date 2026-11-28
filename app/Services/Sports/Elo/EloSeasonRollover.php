<?php

namespace App\Services\Sports\Elo;

use App\Models\SportEloSeasonInitialization;
use App\Services\Predictions\CanonicalPayloadHasher;
use InvalidArgumentException;
use RuntimeException;

class EloSeasonRollover
{
    public function __construct(
        private readonly EloSportRegistry $registry,
        private readonly EloWriterProtocol $protocol,
        private readonly EloFixedScale $decimals,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly EloSourceContext $sources,
    ) {}

    public function inspect(string $sport, int $season, array $factors): array
    {
        return $this->process($sport, $season, $factors, false);
    }

    /** Native admitted pipelines may request a rollover; operator apply supplies the reviewed exact plan. */
    public function execute(string $sport, int $season, array $factors, ?string $expectedFingerprint = null): array
    {
        return $this->process($sport, $season, $factors, true, $expectedFingerprint);
    }

    /** One explicit season transition; never infer or reconstruct missing intervening seasons. */
    private function process(string $sport, int $season, array $factors, bool $apply, ?string $expectedFingerprint = null): array
    {
        if ($expectedFingerprint !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) !== 1) {
            throw new InvalidArgumentException('An exact reviewed rollover fingerprint is required.');
        }
        if ($season < 1900 || $season > (int) now()->year + 1 || $factors === []
            || array_diff(array_keys($factors), $sport === 'mlb' ? ['team', 'pitcher'] : ['team']) !== []) {
            throw new InvalidArgumentException('Select an explicit supported Elo rollover season and entity factors.');
        }
        $this->protocol->requireCapability($sport);
        $normalized = [];
        foreach ($factors as $type => $factor) {
            $normalized[$type] = $this->decimals->factor($factor);
        }
        ksort($normalized);
        $definition = $this->registry->resolve($sport, 'team');
        $connection = (new ($definition['entity_model']))->getConnection();

        return $connection->transaction(function () use ($sport, $season, $normalized, $definition, $connection, $apply, $expectedFingerprint): array {
            $this->protocol->lockGate($connection, $sport, $definition);
            $name = $connection->getName();
            $this->sources->beginObservation();
            $participants = [];
            foreach (array_keys($normalized) as $type) {
                $previewDefinition = $this->registry->resolve($sport, $type);
                $preview = $previewDefinition['entity_model']::on($name)->orderBy('id');
                if ($type === 'pitcher') {
                    $preview->whereNotNull('elo_rating')->whereIn('position', ['P', 'SP', 'RP']);
                }
                $participants[$type] = $preview->limit(10001)->pluck('id')->all();
                if (count($participants[$type]) > 10000) {
                    throw new RuntimeException('Elo rollover preview budget exceeded.');
                }
            }
            $locked = $this->sources->lockGames($connection, $sport, $definition, $participants, [], null, [$season - 1]);
            $items = [];
            // The team/pitcher lock order matches ordinary game publication, regardless of factor order.
            foreach (['team', 'pitcher'] as $type) {
                if (! array_key_exists($type, $normalized)) {
                    continue;
                }
                $entityDefinition = $this->registry->resolve($sport, $type);
                $query = $entityDefinition['entity_model']::on($name)->orderBy('id')->lockForUpdate();
                if ($type === 'pitcher') {
                    $query->whereNotNull('elo_rating')->whereIn('position', ['P', 'SP', 'RP']);
                }
                $entityIds = (clone $query)->limit(EloEligibilityPolicy::LIMITS['entities_per_type'] + 1)->toBase()->pluck('id');
                if ($entityIds->count() > EloEligibilityPolicy::LIMITS['entities_per_type'] || ($type === 'team' && $entityIds->isEmpty())) {
                    throw new RuntimeException('Elo rollover entity budget exceeded or team roster unavailable; considered '.$entityIds->count().' '.$type.' entities.');
                }
                $entities = $entityDefinition['entity_model']::on($name)->whereIn('id', $entityIds->all())->orderBy('id')->lockForUpdate()->get();
                if ($entities->count() !== $entityIds->count()) {
                    throw new RuntimeException('Elo locked entity roster changed.');
                }
                foreach ($entities as $entity) {
                    $items[] = ['type' => $type, 'entity' => $entity, 'definition' => $entityDefinition, 'factor' => $normalized[$type]];
                }
            }
            // Validate the complete selected roster before publishing any transition.
            foreach ($items as &$item) {
                $item['head'] = $this->protocol->lockHead($connection, $sport, $item['type'], $item['entity'], $item['definition']);
                $this->sources->verifyHead($connection, $item['head'], $locked);
                $identity = ['sport' => $sport, 'entity_type' => $item['type'], 'entity_id' => $item['entity']->id,
                    'season' => $season, 'calculation_version' => $definition['version']];
                $prior = SportEloSeasonInitialization::on($name)->where($identity)->lockForUpdate()->first();
                if ($prior !== null) {
                    if ($prior->kind !== 'season_rollover' || $prior->regression_factor !== $item['factor']
                        || $item['head']->season !== $season || (int) $item['head']->initialization_id !== (int) $prior->id) {
                        throw new RuntimeException('Existing Elo season initialization conflicts with the requested rollover.');
                    }
                    // lockHead validated the persisted initialization hash and effective head.
                    $item['reuse'] = $prior;

                    continue;
                }
                if ($item['head']->season + 1 !== $season) {
                    throw new RuntimeException('Elo rollover requires the immediately preceding admitted season.');
                }
                $this->sources->verifyPending($connection, $item['head'], $item['definition'], $locked);
                $historyModel = $item['definition']['history_model'];
                if ($historyModel::on($name)->where($item['definition']['entity_key'], $item['entity']->id)
                    ->where('season', '>=', $season)->lockForUpdate()->first() !== null) {
                    throw new RuntimeException('Unreceipted target-season Elo history requires reviewed reconstruction.');
                }
            }
            unset($item);
            $this->sources->verifyDependencies($connection, $sport, $definition, $locked, array_map(fn ($item) => $item['head'], $items));
            $planItems = [];
            foreach ($items as $item) {
                $head = $item['head'];
                $planItems[] = ['entity_type' => $item['type'], 'entity_id' => (int) $item['entity']->id,
                    'entity_context' => array_intersect_key($item['entity']->getAttributes(), array_flip(['team_id', 'position', 'updated_at'])),
                    'rating' => $this->decimals->rating($item['entity']->getRawOriginal('elo_rating')),
                    'head' => [...$head->only(['id', 'initialization_id', 'last_effect_id', 'season', 'calculation_version', 'configuration_hash',
                        'rating', 'source_fingerprint', 'last_game_id', 'generation']), 'last_played_at_utc' => $head->last_played_at?->format('Y-m-d H:i:s.u')],
                    'initialization_receipt_hash' => $head->getRelation('integrityInitialization')->receipt_hash,
                    'action' => isset($item['reuse']) ? 'reuse' : 'initialize', 'factor' => $item['factor']];
            }
            $plan = ['sport' => $sport, 'season' => $season, 'calculation_version' => $definition['version'],
                'configuration_hash' => $definition['configuration_hash'], 'eligibility' => $definition['eligibility'],
                'considered' => $locked['considered'],
                'write_target_fingerprint' => config('elo_integrity.write_target_fingerprints.'.$sport),
                'factors' => $normalized, 'items' => $planItems,
                'locked_source_fingerprints' => array_map(fn (array $source): string => $this->hasher->hash($source), $locked['sources'])];
            $fingerprint = $this->hasher->hash($plan);
            if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $fingerprint)) {
                throw new RuntimeException('The reviewed Elo rollover plan changed; inspect the current plan again.');
            }
            if (! $apply) {
                return [...$plan, 'fingerprint' => $fingerprint, 'dry_run' => true];
            }
            $ids = [];
            $changed = 0;
            foreach ($items as $item) {
                if (isset($item['reuse'])) {
                    $ids[] = $item['reuse']->id;

                    continue;
                }
                $head = $item['head'];
                $default = $this->decimals->rating($item['definition']['default']);
                $after = $this->decimals->rating((int) round((float) $head->rating + (float) $item['factor'] * ((float) $default - (float) $head->rating)));
                $evidence = ['prior_initialization_id' => (int) $head->initialization_id, 'prior_head_generation' => $head->generation,
                    'prior_head_source_fingerprint' => $head->source_fingerprint, 'prior_season' => $head->season,
                    'last_game_id' => $head->last_game_id, 'last_played_at_utc' => $head->last_played_at?->format('Y-m-d H:i:s.u'),
                    'declared_default' => $default,
                    'prior_game_snapshot' => $head->getRelation('integrityInitialization')->evidence['game']
                        ?? $head->getRelation('integrityInitialization')->evidence['prior_game_snapshot'] ?? null,
                    'original_historical_model_provenance' => 'retained_as_previously_admitted'];
                $payload = ['sport' => $sport, 'entity_type' => $item['type'], 'entity_id' => (int) $item['entity']->id,
                    'season' => $season, 'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
                    'kind' => 'season_rollover', 'baseline_kind' => 'prior_admitted_head', 'rating_before' => $head->rating,
                    'rating_after' => $after, 'prior_rating' => $head->rating, 'regression_factor' => $item['factor'],
                    'prior_effect_id' => $head->last_effect_id, 'source_history_table' => null, 'source_history_id' => null,
                    'source_fingerprint' => $this->hasher->hash(['evidence' => $evidence, 'factor' => $item['factor']]),
                    'evidence' => $evidence, 'review_reference' => null];
                $receipt = SportEloSeasonInitialization::on($name)->create([...$payload, 'receipt_hash' => $this->hasher->hash($payload),
                    'observed_at' => now(), 'recorded_at' => now()]);
                $item['entity']->forceFill(['elo_rating' => (float) $after])->save();
                if ($sport === 'nfl' && $item['type'] === 'team') {
                    $item['definition']['history_model']::on($name)->create(['team_id' => $item['entity']->id, 'game_id' => null,
                        'season' => $season, 'week' => 0, 'date' => now()->toDateString(), 'elo_rating' => (float) $after,
                        'elo_change' => round((float) $after - (float) $head->rating, 1)]);
                }
                $head->forceFill(['initialization_id' => $receipt->id, 'last_effect_id' => null, 'season' => $season,
                    'rating' => $after, 'source_fingerprint' => $payload['source_fingerprint'], 'generation' => $head->generation + 1])->save();
                $ids[] = $receipt->id;
                $changed++;
            }

            return ['fingerprint' => $fingerprint, 'receipt_ids' => $ids, 'changed_entities' => $changed, 'reused_entities' => count($items) - $changed];
        }, 3);
    }
}
