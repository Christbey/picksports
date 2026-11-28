<?php

namespace App\Services\Sports\Elo;

use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EloWriterProtocol
{
    public function __construct(
        private readonly EloWriterCapabilities $capabilities,
        private readonly EloWriteTargetIdentity $targets,
        private readonly EloFixedScale $decimals,
        private readonly CanonicalPayloadHasher $hasher,
    ) {}

    public function requireCapability(string $sport): void
    {
        if (! config('elo_integrity.enabled', false) || ! $this->capabilities->supports($sport, 1)) {
            throw new RuntimeException('A complete coordinated Elo writer adapter is required; no legacy fallback is allowed.');
        }
    }

    /** Caller owns the transaction; this stable gate always precedes game/entity locks. */
    public function lockGate(Connection $connection, string $sport, array $definition): SportEloWriterGate
    {
        $this->requireCapability($sport);
        app(EloSqlClockPolicy::class)->assertCurrent($connection);
        $gate = SportEloWriterGate::on($connection->getName())->whereKey($sport)->lockForUpdate()->first();
        $target = $this->targets->describe($connection);
        if ($connection->getDriverName() === 'mysql') {
            $isolation = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level', [], false);
            if (($isolation->isolation_level ?? null) !== 'REPEATABLE-READ') {
                throw new RuntimeException('Guarded Elo requires MySQL repeatable-read range locking; no fallback is allowed.');
            }
        } elseif ($connection->getDriverName() === 'sqlite') {
            $isolation = $connection->selectOne('PRAGMA read_uncommitted', [], false);
            if ((int) ($isolation->read_uncommitted ?? 1) !== 0) {
                throw new RuntimeException('Guarded Elo refuses SQLite uncommitted reads.');
            }
        }

        $expected = config('elo_integrity.write_target_fingerprints.'.$sport);
        if ($gate === null || $gate->mode !== 'active' || $gate->protocol_version !== 1
            || $gate->calculation_version !== $definition['version'] || $gate->configuration_hash !== $definition['configuration_hash']
            || ! is_string($gate->drain_reference) || trim($gate->drain_reference) === ''
            || $gate->drain_verified_at === null || $gate->drain_verified_at->isFuture()
            || ! is_string($expected) || ! hash_equals($target['fingerprint'], $expected)) {
            throw new RuntimeException('Elo writer gate, version, configuration, drain or physical target is not active and coherent.');
        }

        return $gate;
    }

    public function lockHead(Connection $connection, string $sport, string $type, Model $entity, array $definition): SportEloEntityHead
    {
        $name = $connection->getName();
        $head = SportEloEntityHead::on($name)->where(['sport' => $sport, 'entity_type' => $type, 'entity_id' => $entity->getKey()])->lockForUpdate()->first();
        if ($head === null || $head->calculation_version !== $definition['version']
            || $head->configuration_hash !== $definition['configuration_hash']
            || $head->rating !== $this->decimals->rating($entity->getRawOriginal('elo_rating'))) {
            throw new RuntimeException('Elo entity requires coherent reviewed baseline admission; legacy history is not an implicit baseline.');
        }
        $initialization = SportEloSeasonInitialization::on($name)->whereKey($head->initialization_id)->lockForUpdate()->first();
        $fields = ['sport', 'entity_type', 'entity_id', 'season', 'calculation_version', 'configuration_hash', 'kind',
            'baseline_kind', 'rating_before', 'rating_after', 'prior_rating', 'regression_factor', 'prior_effect_id',
            'source_history_table', 'source_history_id', 'source_fingerprint', 'evidence', 'review_reference'];
        if ($initialization === null || $initialization->sport !== $sport || $initialization->entity_type !== $type
            || $initialization->entity_id !== (int) $entity->getKey() || $initialization->season !== $head->season
            || $initialization->calculation_version !== $definition['version'] || $initialization->configuration_hash !== $definition['configuration_hash']
            || ! hash_equals($initialization->receipt_hash, $this->hasher->hash($initialization->only($fields)))) {
            throw new RuntimeException('Elo initialization receipt is inconsistent.');
        }
        if ($head->last_effect_id === null) {
            $expectedGame = $initialization->evidence['game']['id'] ?? $initialization->evidence['last_game_id'] ?? null;
            $expectedTime = $initialization->evidence['game']['played_at_utc'] ?? $initialization->evidence['last_played_at_utc'] ?? null;
            if ($head->rating !== $initialization->rating_after || $head->source_fingerprint !== $initialization->source_fingerprint
                || $head->last_game_id !== ($expectedGame === null ? null : (int) $expectedGame)
                || $head->last_played_at?->format('Y-m-d H:i:s.u') !== $expectedTime) {
                throw new RuntimeException('Elo baseline head differs from its receipt.');
            }
        } else {
            $effect = SportEloApplicationEffect::on($name)->whereKey($head->last_effect_id)->lockForUpdate()->first();
            if ($effect === null || $effect->entity_type !== $type || $effect->entity_id !== (int) $entity->getKey()
                || (int) $effect->initialization_id !== (int) $head->initialization_id || $effect->rating_after !== $head->rating) {
                throw new RuntimeException('Elo head differs from its effective receipt.');
            }
            $application = SportEloApplication::on($name)->whereKey($effect->application_id)->lockForUpdate()->first();
            $payload = $application?->only(['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint', 'source_snapshot']);
            if ($application !== null) {
                $payload['played_at'] = $application->played_at->format('Y-m-d H:i:s.u');
            }
            $expected = $application?->source_snapshot['expected_effects'][$type.':'.$entity->id] ?? null;
            $actual = $effect->only(['entity_type', 'entity_id', 'team_id', 'initialization_id', 'previous_effect_id',
                'rating_before', 'rating_after', 'rating_delta', 'games_started_before', 'games_started_after']);
            foreach (['team_id', 'initialization_id', 'previous_effect_id'] as $key) {
                $actual[$key] = $actual[$key] === null ? null : (int) $actual[$key];
            }
            $actual['legacy_history_delta'] = $effect->evidence['legacy_history_delta'] ?? null;
            if ($application === null || $application->sport !== $sport || $application->game_id !== $head->last_game_id
                || $application->played_at->format('Y-m-d H:i:s.u') !== $head->last_played_at?->format('Y-m-d H:i:s.u')
                || $application->calculation_version !== $definition['version'] || $application->configuration_hash !== $definition['configuration_hash']
                || $application->source_fingerprint !== $head->source_fingerprint
                || ! hash_equals($application->receipt_hash, $this->hasher->hash($payload))
                || ! is_array($expected) || $this->hasher->hash($expected) !== $this->hasher->hash($actual)) {
                throw new RuntimeException('Elo head/effect differs from its hashed application receipt.');
            }
            $historyModel = $definition['history_model'];
            $history = $historyModel::on($name)->whereKey($effect->evidence['history_id'] ?? null)->lockForUpdate()->first();
            if ($history === null || $history->getTable() !== ($effect->evidence['history_table'] ?? null)
                || (int) $history->game_id !== $head->last_game_id || (int) $history->{$definition['entity_key']} !== (int) $entity->id
                || $this->decimals->rating($history->getRawOriginal('elo_rating')) !== $head->rating
                || $this->decimals->rating($history->getRawOriginal('elo_change')) !== $actual['legacy_history_delta']
                || ($type === 'pitcher' && ((int) $history->games_started !== $effect->games_started_after || (int) $history->team_id !== (int) $effect->team_id))) {
                throw new RuntimeException('Elo head history differs from its effective receipt.');
            }
        }

        $head->setRelation('integrityInitialization', $initialization);

        return $head;
    }
}
