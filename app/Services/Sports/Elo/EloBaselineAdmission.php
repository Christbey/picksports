<?php

namespace App\Services\Sports\Elo;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\ESPN\ObservedStatValue;
use App\Support\MLB\MlbGameStart;
use DomainException;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

class EloBaselineAdmission
{
    public function __construct(
        private readonly EloSportRegistry $registry,
        private readonly EloFixedScale $decimals,
        private readonly EloWriterCapabilities $capabilities,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly EloWriteTargetIdentity $targets,
    ) {}

    public function inspect(string $sport, int $season, string $type, array $ids, ?string $connectionName = null): array
    {
        [$definition, $ids] = $this->selection($sport, $season, $type, $ids);
        $connection = $this->connection($definition, $connectionName);

        return $connection->transaction(function () use ($connection, $sport, $season, $type, $ids, $definition): array {
            $target = $this->targets->describe($connection);

            return $this->report($sport, $season, $type, $definition,
                $this->items($connection, $sport, $season, $type, $ids, $definition), $connection, $target);
        });
    }

    public function apply(string $sport, int $season, string $type, array $ids, string $fingerprint, string $reviewReference, ?string $connectionName = null): array
    {
        [$definition, $ids] = $this->selection($sport, $season, $type, $ids);
        if (! config('elo_integrity.enabled', false) || ! $this->capabilities->supports($sport, 1)) {
            throw new RuntimeException('Elo baseline apply requires a complete coordinated writer adapter.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1 || trim($reviewReference) === '' || strlen($reviewReference) > 191) {
            throw new InvalidArgumentException('Apply requires an exact fingerprint and bounded review reference.');
        }
        $connection = $this->connection($definition, $connectionName);
        $clock = app(EloSqlClockPolicy::class)->capture($connection);
        $planned = $this->inspect($sport, $season, $type, $ids, $connection->getName());
        $this->assertReport($planned, $fingerprint);

        return $connection->transaction(function () use ($connection, $sport, $season, $type, $ids, $definition, $planned, $fingerprint, $reviewReference, $clock): array {
            app(EloSqlClockPolicy::class)->assertUnchanged($clock, $connection);
            $name = $connection->getName();
            $gate = SportEloWriterGate::on($name)->whereKey($sport)->lockForUpdate()->firstOrFail();
            if (! $this->gateCoherent($gate, $definition)) {
                throw new RuntimeException('Elo writer mode or drain evidence is incoherent for admission.');
            }
            $target = $this->targets->describe($connection);
            $gameIds = collect($planned['items'])->pluck('evidence.game.id')->filter()->unique()->sort()->values()->all();
            $gameTable = $this->table($definition['game_model']);
            $connection->table($gameTable)->whereIn('id', $gameIds)->orderBy('id')->lockForUpdate()->get(['id']);
            // Source games are locked before entity rows; no unplanned game may be added later.
            $items = $this->items($connection, $sport, $season, $type, $ids, $definition, true, $gameIds);
            $current = $this->report($sport, $season, $type, $definition, $items, $connection, $target);
            $this->assertReport($current, $fingerprint);
            $receipts = [];
            foreach ($items as $item) {
                $identity = ['sport' => $sport, 'entity_type' => $type, 'entity_id' => $item['entity_id'],
                    'season' => $season, 'calculation_version' => $definition['version']];
                $head = SportEloEntityHead::on($name)->where(array_intersect_key($identity, array_flip(['sport', 'entity_type', 'entity_id'])))->lockForUpdate()->first();
                $prior = SportEloSeasonInitialization::on($name)->where($identity)->lockForUpdate()->first();
                if ($prior !== null || $head !== null) {
                    if ($prior === null || $head === null || $head->initialization_id !== $prior->id || $head->last_effect_id !== null
                        || $head->source_fingerprint !== $item['source_fingerprint'] || $prior->source_fingerprint !== $item['source_fingerprint']
                        || $head->season !== $season || $head->generation !== 1 || ! $this->headChronologyMatches($head, $item) || ! $this->receiptMatches($prior, $item, $definition)
                        || $head->calculation_version !== $definition['version'] || $head->configuration_hash !== $definition['configuration_hash']
                        || $this->decimals->rating($head->getRawOriginal('rating')) !== $item['rating']) {
                        throw new RuntimeException('Existing Elo admission or head conflicts; reviewed reconciliation is required.');
                    }
                    $receipts[] = $prior->id;

                    continue;
                }
                $payload = [...$identity, 'configuration_hash' => $definition['configuration_hash'], 'kind' => 'baseline_admission',
                    'baseline_kind' => $item['baseline_kind'], 'rating_before' => $item['rating'], 'rating_after' => $item['rating'],
                    'prior_rating' => null, 'regression_factor' => null, 'prior_effect_id' => null,
                    'source_history_table' => $item['evidence']['history_table'], 'source_history_id' => $item['evidence']['history']['id'] ?? null,
                    'source_fingerprint' => $item['source_fingerprint'], 'evidence' => $item['evidence'], 'review_reference' => $reviewReference];
                $receipt = SportEloSeasonInitialization::on($name)->create([...$payload, 'receipt_hash' => $this->hasher->hash($payload),
                    'observed_at' => now(), 'recorded_at' => now()]);
                $game = $item['evidence']['game'];
                SportEloEntityHead::on($name)->create([...array_intersect_key($identity, array_flip(['sport', 'entity_type', 'entity_id', 'season', 'calculation_version'])),
                    'initialization_id' => $receipt->id, 'configuration_hash' => $definition['configuration_hash'],
                    'rating' => $item['rating'], 'source_fingerprint' => $item['source_fingerprint'],
                    'last_game_id' => $game['id'] ?? null, 'last_played_at' => $game === null ? null
                        : $game['played_at_utc']]);
                $receipts[] = $receipt->id;
            }

            app(EloSqlClockPolicy::class)->assertUnchanged($clock, $connection);

            return ['fingerprint' => $current['fingerprint'], 'receipt_ids' => $receipts, 'legacy_projections_changed' => false];
        }, 3);
    }

    private function headChronologyMatches(SportEloEntityHead $head, array $item): bool
    {
        $game = $item['evidence']['game'];

        return $head->last_game_id === ($game === null ? null : (int) $game['id'])
            && $head->last_played_at?->format('Y-m-d H:i:s.u') === ($game['played_at_utc'] ?? null);
    }

    private function receiptMatches(SportEloSeasonInitialization $receipt, array $item, array $definition): bool
    {
        $fields = ['sport', 'entity_type', 'entity_id', 'season', 'calculation_version', 'configuration_hash', 'kind',
            'baseline_kind', 'rating_before', 'rating_after', 'prior_rating', 'regression_factor', 'prior_effect_id', 'source_history_table', 'source_history_id',
            'source_fingerprint', 'evidence', 'review_reference'];

        return $receipt->kind === 'baseline_admission' && $receipt->baseline_kind === $item['baseline_kind']
            && $receipt->configuration_hash === $definition['configuration_hash'] && $receipt->prior_rating === null && $receipt->regression_factor === null
            && $receipt->prior_effect_id === null && $receipt->rating_before === $item['rating'] && $receipt->rating_after === $item['rating']
            && hash_equals($receipt->receipt_hash, $this->hasher->hash($receipt->only($fields)));
    }

    private function selection(string $sport, int $season, string $type, array $ids): array
    {
        $definition = $this->registry->resolve($sport, $type);
        if ($season < 1900 || $season > (int) now()->year + 1 || $ids === [] || count($ids) > 100) {
            throw new InvalidArgumentException('Select an explicit valid season and between one and 100 entity IDs.');
        }
        $normalized = [];
        foreach ($ids as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT);
            if ((! is_int($id) && ! is_string($id)) || $value === false || $value < 1 || isset($normalized[$value])) {
                throw new InvalidArgumentException('Entity IDs must be distinct positive integers.');
            }
            $normalized[$value] = $value;
        }
        sort($normalized, SORT_NUMERIC);

        return [$definition, $normalized];
    }

    private function table(string $class): string
    {
        return (new $class)->getTable();
    }

    private function connection(array $definition, ?string $name): Connection
    {
        $class = $definition['entity_model'];
        $entity = new $class;
        if ($name !== null) {
            $entity->setConnection($name);
        }

        return $entity->getConnection();
    }

    private function report(string $sport, int $season, string $type, array $definition, array $items, Connection $connection, array $target): array
    {
        $payload = ['sport' => $sport, 'season' => $season, 'entity_type' => $type, 'calculation_version' => $definition['version'],
            'configuration_hash' => $definition['configuration_hash'], 'target' => $target, 'items' => $items];

        return [...$payload, 'fingerprint' => $this->hasher->hash($payload),
            'apply_available' => (bool) config('elo_integrity.enabled', false) && $this->capabilities->supports($sport, 1)
                && $this->gateCoherent(SportEloWriterGate::on($connection->getName())->whereKey($sport)->first(), $definition)];
    }

    private function gateCoherent(?SportEloWriterGate $gate, array $definition): bool
    {
        return $gate !== null && $gate->mode === 'admitting' && $gate->protocol_version === 1
            && (int) config('elo_integrity.protocol_version') === 1 && $gate->calculation_version === $definition['version']
            && $gate->configuration_hash === $definition['configuration_hash'] && trim((string) $gate->drain_reference) !== ''
            && $gate->drain_verified_at !== null && ! $gate->drain_verified_at->isFuture();
    }

    private function assertReport(array $report, string $fingerprint): void
    {
        if (! hash_equals($report['fingerprint'], $fingerprint)
            || collect($report['items'])->contains(fn (array $item): bool => $item['status'] !== 'admissible')) {
            throw new RuntimeException('Elo baseline source changed or contains unverified legacy evidence.');
        }
    }

    private function items(Connection $connection, string $sport, int $season, string $type, array $ids, array $definition, bool $locking = false, array $allowedGames = []): array
    {
        $entityTable = $this->table($definition['entity_model']);
        $historyTable = $this->table($definition['history_model']);
        $entities = $connection->table($entityTable)->whereIn('id', $ids)->orderBy('id')
            ->when($locking, fn ($q) => $q->lockForUpdate())->get($type === 'team' ? ['id', 'elo_rating', 'updated_at'] : ['id', 'elo_rating', 'updated_at', 'team_id', 'position'])->keyBy('id');
        $items = [];
        foreach ($ids as $id) {
            try {
                $entity = $entities->get($id);
                if ($entity === null || ($type === 'pitcher' && ! in_array($entity->position, ['P', 'SP', 'RP'], true))) {
                    throw new DomainException('Entity is missing or its declared role is unavailable.');
                }
                $rating = $this->decimals->rating($entity->elo_rating);
                $history = $connection->table($historyTable)->where($definition['entity_key'], $id)->orderByDesc('season')
                    ->orderByDesc($definition['history_date'])->orderByDesc('id')->when($locking, fn ($q) => $q->lockForUpdate())->limit(2)->get();
                $latest = $history->first();
                $game = null;
                $pair = [];
                if ($latest === null) {
                    if ($rating !== $this->decimals->rating($definition['default'])) {
                        throw new DomainException('A nondefault legacy projection has no supporting history.');
                    }
                    $kind = 'declared_default';
                } else {
                    if ((int) $latest->season !== $season || $rating !== $this->decimals->rating($latest->elo_rating)) {
                        throw new DomainException('Current projection and latest stored season history disagree.');
                    }
                    $second = $history->get(1);
                    if ($second !== null && $second->season === $latest->season && $second->{$definition['history_date']} === $latest->{$definition['history_date']}
                        && $second->game_id === $latest->game_id) {
                        throw new DomainException('Latest legacy history identity is ambiguous.');
                    }
                    if ($latest->game_id === null) {
                        if ($sport !== 'nfl' || $type !== 'team' || (int) ($latest->week ?? -1) !== 0) {
                            throw new DomainException('Legacy history has no verifiable game context.');
                        }
                    } else {
                        if ($locking && ! in_array((int) $latest->game_id, array_map('intval', $allowedGames), true)) {
                            throw new DomainException('Source game set changed before admission.');
                        }
                        $columns = ['id', 'espn_event_id', 'season', 'season_type', 'week', 'game_date', 'game_time',
                            'status', 'home_team_id', 'away_team_id', 'home_score', 'away_score'];
                        if ($sport !== 'mlb') {
                            $columns[] = 'neutral_site';
                        } else {
                            $columns = [...$columns, ...MlbGameStart::FIELDS];
                        }
                        $game = $connection->table($this->table($definition['game_model']))->where('id', $latest->game_id)
                            ->when($locking, fn ($q) => $q->lockForUpdate())->first($columns);
                        if ($game === null || (int) $game->season !== $season || ! in_array($game->status, GameData::finalStatuses(), true)
                            || ! $game->game_date || ! $game->game_time || ! $game->home_team_id || ! $game->away_team_id || $game->home_team_id === $game->away_team_id) {
                            throw new DomainException('Current legacy source game context is incomplete.');
                        }
                        try {
                            $sourceModel = (new ($definition['game_model']))->newFromBuilder((array) $game, $connection->getName());
                            $game->played_at_utc = EloSourceClock::observed($sourceModel)->format('Y-m-d H:i:s.u');
                        } catch (InvalidArgumentException) {
                            throw new DomainException('Current legacy source chronology is unavailable or invalid.');
                        }
                        ObservedStatValue::integer($game->home_score);
                        ObservedStatValue::integer($game->away_score);
                        $pair = $connection->table($historyTable)->where('game_id', $game->id)->orderBy('id')
                            ->when($locking, fn ($q) => $q->lockForUpdate())->limit(3)->get()->all();
                        $teams = array_map(fn ($row): int => (int) $row->team_id, $pair);
                        sort($teams);
                        $expected = [(int) $game->home_team_id, (int) $game->away_team_id];
                        sort($expected);
                        if (count($pair) !== 2 || $teams !== $expected || ($type === 'pitcher' && count(array_unique(array_column($pair, 'player_id'))) !== 2)) {
                            throw new DomainException('Legacy history does not contain a coherent pair.');
                        }
                    }
                    $kind = 'reviewed_legacy_current';
                }
                $evidence = ['entity_table' => $entityTable, 'entity' => array_intersect_key((array) $entity, array_flip(['id', 'team_id', 'position', 'updated_at'])),
                    'rating' => $rating, 'history_table' => $historyTable, 'history' => $latest === null ? null : $this->historyEvidence($latest),
                    'pair' => array_map(fn ($row): array => $this->historyEvidence($row), $pair), 'game' => $game === null ? null : (array) $game,
                    'historical_calculation_provenance' => $latest === null ? 'declared_default_now' : 'unknown_original_model_and_observation_time'];
                $items[] = ['entity_id' => $id, 'status' => 'admissible', 'baseline_kind' => $kind, 'rating' => $rating,
                    'source_fingerprint' => $this->hasher->hash($evidence), 'evidence' => $evidence];
            } catch (DomainException $error) {
                $items[] = ['entity_id' => $id, 'status' => 'legacy_unknown', 'reason' => $error->getMessage()];
            }
        }

        return $items;
    }

    private function historyEvidence(object $row): array
    {
        $result = (array) $row;
        $result['elo_rating'] = $this->decimals->rating($row->elo_rating);
        if ($row->elo_change !== null) {
            $result['elo_change'] = $this->decimals->rating($row->elo_change);
        }

        return $result;
    }
}
