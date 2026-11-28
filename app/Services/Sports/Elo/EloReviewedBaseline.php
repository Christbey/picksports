<?php

namespace App\Services\Sports\Elo;

use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EloReviewedBaseline
{
    public function __construct(private readonly CanonicalPayloadHasher $hasher, private readonly EloFixedScale $decimals) {}

    public function find(Connection $connection, SportEloEntityHead $head, int $season): ?SportEloSeasonInitialization
    {
        return SportEloSeasonInitialization::on($connection->getName())->where(['sport' => $head->sport, 'entity_type' => $head->entity_type,
            'entity_id' => $head->entity_id, 'season' => $season, 'calculation_version' => $head->calculation_version,
            'kind' => 'baseline_admission', 'baseline_kind' => 'reviewed_legacy_current'])->lockForUpdate()->first();
    }

    /** Immutable reviewed cutoff, never a later mutable head or a fabricated per-game receipt. */
    public function cutoff(Connection $connection, SportEloEntityHead $head, int $season, array $definition, array $locked): ?array
    {
        $receipt = $this->find($connection, $head, $season);
        if ($receipt === null) {
            return null;
        }
        $fields = ['sport', 'entity_type', 'entity_id', 'season', 'calculation_version', 'configuration_hash', 'kind', 'baseline_kind',
            'rating_before', 'rating_after', 'prior_rating', 'regression_factor', 'prior_effect_id', 'source_history_table', 'source_history_id',
            'source_fingerprint', 'evidence', 'review_reference'];
        $evidence = $receipt->evidence;
        $expected = $evidence['game'] ?? null;
        $gameId = is_array($expected) ? (int) ($expected['id'] ?? 0) : 0;
        $game = $locked['games'][$gameId] ?? null;
        if ($receipt->configuration_hash !== $definition['configuration_hash'] || $receipt->prior_rating !== null
            || $receipt->regression_factor !== null || $receipt->prior_effect_id !== null
            || $receipt->rating_before !== $receipt->rating_after || $receipt->rating_after !== ($evidence['rating'] ?? null)
            || ! hash_equals($receipt->receipt_hash, $this->hasher->hash($receipt->only($fields)))
            || ! hash_equals($receipt->source_fingerprint, $this->hasher->hash($evidence))
            || $game === null || (int) $game->season !== $season
            || $receipt->source_history_table !== (new ($definition['history_model']))->getTable()
            || (int) ($evidence['history']['id'] ?? 0) !== $receipt->source_history_id
            || (int) ($evidence['history'][$definition['entity_key']] ?? 0) !== $head->entity_id
            || (int) ($evidence['history']['game_id'] ?? 0) !== $gameId) {
            throw new RuntimeException('Reviewed Elo baseline/source linkage is incomplete or changed.');
        }
        $current = array_intersect_key($game->getAttributes(), $expected);
        $time = EloSourceClock::observed($game);
        $current['played_at_utc'] = $time->format('Y-m-d H:i:s.u');
        if ($this->hasher->hash($current) !== $this->hasher->hash($expected)) {
            throw new RuntimeException('Reviewed Elo baseline source changed; reconciliation is required.');
        }
        $rows = $connection->table($receipt->source_history_table)->where('game_id', $gameId)->orderBy('id')->lockForUpdate()->limit(3)->get();
        $actualPair = $rows->map(function ($row): array {
            $values = (array) $row;
            $values['elo_rating'] = $this->decimals->rating($row->elo_rating);
            if ($row->elo_change !== null) {
                $values['elo_change'] = $this->decimals->rating($row->elo_change);
            }

            return $values;
        })->all();
        $own = collect($actualPair)->first(fn ($row): bool => (int) $row['id'] === $receipt->source_history_id);
        if (count($actualPair) !== 2 || $own === null || ($evidence['history']['elo_rating'] ?? null) !== $receipt->rating_after
            || $this->hasher->hash($own) !== $this->hasher->hash($evidence['history'] ?? null)
            || $this->hasher->hash($actualPair) !== $this->hasher->hash($evidence['pair'] ?? null)) {
            throw new RuntimeException('Reviewed Elo baseline history pair changed.');
        }

        return ['receipt_id' => (int) $receipt->id, 'receipt_hash' => $receipt->receipt_hash, 'source_fingerprint' => $receipt->source_fingerprint,
            'game_id' => $gameId, 'played_at_utc' => $time->format('Y-m-d H:i:s.u')];
    }

    public function includes(Model $game, ?array $cutoff): bool
    {
        if ($cutoff === null) {
            return false;
        }
        $playedAt = EloSourceClock::observed($game)->format('Y-m-d H:i:s.u');

        return [$playedAt, (int) $game->id] <= [$cutoff['played_at_utc'], $cutoff['game_id']];
    }
}
