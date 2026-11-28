<?php

namespace App\Services\Sports\Elo;

use App\Casts\UtcEloDateTime;
use App\Exceptions\EloAdmissionRefused;
use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/** On-demand native dependency closure; reviewed baselines remain explicitly opaque boundaries. */
class EloDependencyGraph
{
    public const CEILINGS = ['applications' => 5000, 'effects' => 20000, 'initializations' => 10000,
        'receipt_bytes' => 32768, 'total_receipt_bytes' => 16777216];

    private const MODELS = ['applications' => SportEloApplication::class, 'effects' => SportEloApplicationEffect::class,
        'initializations' => SportEloSeasonInitialization::class];

    private const APP_FIELDS = ['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint', 'source_snapshot'];

    private const INIT_FIELDS = ['sport', 'entity_type', 'entity_id', 'season', 'calculation_version', 'configuration_hash', 'kind', 'baseline_kind',
        'rating_before', 'rating_after', 'prior_rating', 'regression_factor', 'prior_effect_id', 'source_history_table', 'source_history_id',
        'source_fingerprint', 'evidence', 'review_reference'];

    public function __construct(private readonly CanonicalPayloadHasher $hasher, private readonly EloFixedScale $decimals,
        private readonly EloSportRegistry $registry, private readonly EloReviewedBaseline $baselines) {}

    public static function policy(): array
    {
        $limits = [];
        foreach (self::CEILINGS as $key => $ceiling) {
            $value = config('elo_integrity.dependency_limits.'.$key, $ceiling);
            if (! is_int($value) || $value < 1 || $value > $ceiling) {
                throw new RuntimeException('Invalid bounded Elo dependency policy.');
            }
            $limits[$key] = $value;
        }

        return ['version' => 'native-complete-application-closure-known-finals-v3-selected-history', 'opaque_boundary' => 'exact_reviewed_or_declared_initialization',
            'selected_history' => MlbEloSelectedHistories::policy(),
            'correction' => 'refuse_without_replay', 'allocation' => 'raw_bounded_discovery_streamed_current_verification', 'limits' => $limits];
    }

    /** Snapshot previews are permitted only for planning; current roots must match before publication. */
    public function plan(Connection $connection, string $sport, array $definition, iterable $heads, array $targetGames): array
    {
        $plan = ['policy' => self::policy(), 'roots' => [], 'target_apps' => [], 'applications' => [], 'effects' => [],
            'initializations' => [], 'app_effects' => [], 'game_ids' => [], 'ranges' => []];
        $pending = ['applications' => [], 'effects' => [], 'initializations' => []];
        foreach ($heads as $head) {
            $root = $this->root($head);
            $plan['roots'][$head->entity_type.':'.$head->entity_id] = $root;
            $pending['initializations'][$this->id($head->initialization_id)] = true;
            if ($head->last_effect_id !== null) {
                $pending['effects'][$this->id($head->last_effect_id)] = true;
            }
        }
        if ($targetGames !== []) {
            $rows = $connection->table('sport_elo_applications')->where('sport', $sport)->where('calculation_version', $definition['version'])
                ->whereIn('game_id', $targetGames)->orderBy('id')->limit(count($targetGames) + 1)->get(['id', 'game_id']);
            foreach ($rows as $row) {
                $plan['target_apps'][$this->id($row->game_id)] = $this->id($row->id);
                $pending['applications'][$this->id($row->id)] = true;
            }
        }
        $budget = ['bytes' => 0, 'seen' => []];
        while (array_filter($pending) !== []) {
            foreach (array_keys(self::MODELS) as $kind) {
                $ids = array_slice(array_keys($pending[$kind]), 0, 32);
                if ($ids === []) {
                    continue;
                }
                foreach ($ids as $id) {
                    unset($pending[$kind][$id]);
                }
                $ids = array_values(array_filter($ids, fn ($id) => ! isset($plan[$kind][$id])));
                if ($ids === []) {
                    continue;
                }
                if (count($plan[$kind]) + count($ids) > $plan['policy']['limits'][$kind]) {
                    $plan['considered'][$kind] = count($plan[$kind]) + count($ids);
                    $this->refuse('dependency_'.$kind.'_budget', $plan);
                }
                $model = self::MODELS[$kind];
                $table = (new $model)->getTable();
                $this->preflightBytes($connection, $table, $kind, $ids, $plan, $budget);
                $rows = $connection->table($table)->whereIn('id', $ids)->orderBy('id')->get();
                if ($rows->count() !== count($ids)) {
                    $this->refuse('dependency_receipt_missing', $plan);
                }
                foreach ($rows as $row) {
                    $data = $this->decode($kind, $row, $connection, $plan, $budget);
                    $id = $this->id($row->id);
                    $this->scope($kind, $data, $sport, $definition, $plan);
                    $plan[$kind][$id] = $this->hasher->hash($data);
                    if ($kind === 'applications') {
                        $plan['game_ids'][$this->id($data['game_id'])] = true;
                        $expected = $data['source_snapshot']['expected_effects'] ?? null;
                        if (! is_array($expected) || ! in_array(count($expected), [2, 4], true)) {
                            $this->refuse('dependency_effect_pair_incomplete', $plan);
                        }
                        foreach ($expected as $effect) {
                            $type = $effect['entity_type'] ?? null;
                            if (! in_array($type, $sport === 'mlb' ? ['team', 'pitcher'] : ['team'], true)) {
                                $this->refuse('dependency_entity_identity_invalid', $plan);
                            }
                            $this->addWindow($plan, $data['season'], ['entity_type' => $type, 'entity_id' => $this->id($effect['entity_id'] ?? null),
                                'initialization_id' => $this->id($effect['initialization_id'] ?? null),
                                'through' => [$data['played_at'], $data['game_id']]]);
                        }
                        $effects = $connection->table('sport_elo_application_effects')->where('application_id', $id)->orderBy('id')->limit(5)->pluck('id')->all();
                        if (count($effects) !== count($expected)) {
                            $this->refuse('dependency_effect_pair_incomplete', $plan);
                        }
                        $plan['app_effects'][$id] = array_map($this->id(...), $effects);
                        foreach ($plan['app_effects'][$id] as $effect) {
                            $pending['effects'][$effect] = true;
                        }
                    } elseif ($kind === 'effects') {
                        $pending['applications'][$this->id($data['application_id'])] = true;
                        $pending['initializations'][$this->id($data['initialization_id'])] = true;
                        if ($data['previous_effect_id'] !== null) {
                            $pending['effects'][$this->id($data['previous_effect_id'])] = true;
                        }
                    } else {
                        if ($data['prior_effect_id'] !== null) {
                            $pending['effects'][$this->id($data['prior_effect_id'])] = true;
                        }
                        if ($data['kind'] === 'season_rollover') {
                            $pending['initializations'][$this->id($data['evidence']['prior_initialization_id'] ?? null)] = true;
                            $this->addWindow($plan, $data['season'] - 1, ['entity_type' => $data['entity_type'], 'entity_id' => $data['entity_id'],
                                'initialization_id' => $this->id($data['evidence']['prior_initialization_id'] ?? null), 'through' => null]);
                        } elseif ($data['kind'] !== 'baseline_admission') {
                            $this->refuse('dependency_initialization_kind_unknown', $plan);
                        }
                        $gameId = $data['evidence']['game']['id'] ?? $data['evidence']['last_game_id'] ?? null;
                        if ($gameId !== null) {
                            $plan['game_ids'][$this->id($gameId)] = true;
                        }
                    }
                }
            }
        }
        $plan['receipt_bytes'] = $budget['bytes'];
        $plan['game_ids'] = array_keys($plan['game_ids']);
        sort($plan['game_ids'], SORT_NUMERIC);

        return $plan;
    }

    /** Caller has already locked the complete planned source set and current participants/heads. */
    public function verify(Connection $connection, string $sport, array $definition, array $plan, array $locked, iterable $heads, ?SportEloApplication $target = null, array $requestedHistory = []): array
    {
        $selected = MlbEloSelectedHistories::normalize($sport, $requestedHistory);
        $proofs = [];
        if ($this->hasher->hash($plan['policy']) !== $this->hasher->hash(self::policy())) {
            $this->refuse('dependency_policy_changed', $plan);
        }
        $roots = [];
        foreach ($heads as $head) {
            $roots[$head->entity_type.':'.$head->entity_id] = $this->root($head);
        }
        if ($this->hasher->hash($roots) !== $this->hasher->hash($plan['roots'])) {
            $this->refuse('dependency_roots_changed_outside_plan', $plan);
        }
        if ($target !== null && ($plan['target_apps'][$target->game_id] ?? null) !== (int) $target->id) {
            $this->refuse('dependency_target_changed_outside_plan', $plan);
        }
        $cache = ['applications' => [], 'effects' => [], 'initializations' => []];
        $budget = ['bytes' => 0, 'seen' => []];
        foreach (array_keys($plan['applications']) as $id) {
            $application = $this->current('applications', $id, $connection, $plan, $cache, $budget);
            $source = $locked['sources'][$application['game_id']] ?? null;
            if ($source === null || ! hash_equals($application['source_fingerprint'], $this->hasher->hash($source))) {
                $this->refuse('transitive_dependency_source_correction', $plan, $application['game_id']);
            }
            $actualIds = $connection->table('sport_elo_application_effects')->where('application_id', $id)->orderBy('id')->lockForUpdate()->limit(5)->pluck('id')->all();
            if (array_map($this->id(...), $actualIds) !== $plan['app_effects'][$id]) {
                $this->refuse('dependency_effect_set_changed', $plan);
            }
            $expected = $application['source_snapshot']['expected_effects'];
            $keys = [];
            foreach ($actualIds as $effectId) {
                $effect = $this->current('effects', $this->id($effectId), $connection, $plan, $cache, $budget);
                $key = $effect['entity_type'].':'.$effect['entity_id'];
                if (isset($keys[$key]) || ! is_array($expected[$key] ?? null) || $this->hasher->hash($this->expectedEffect($effect)) !== $this->hasher->hash($expected[$key])) {
                    $this->refuse('dependency_effect_receipt_mismatch', $plan);
                }
                $keys[$key] = true;
                $history = $this->verifyEffect($effect, $application, $sport, $definition, $connection, $plan, $locked, $cache, $budget);
                $this->retainSelected($sport, $effect['entity_type'], $effect['entity_id'], $history->getAttributes(),
                    ['kind' => 'native_effect', 'application_id' => $id, 'application_hash' => $plan['applications'][$id],
                        'effect_id' => $effect['id'], 'effect_hash' => $plan['effects'][$effect['id']],
                        'initialization_id' => $effect['initialization_id']], $selected, $proofs, $plan);
            }
            if (count($keys) !== count($expected)) {
                $this->refuse('dependency_effect_pair_incomplete', $plan);
            }
            $this->verifyParticipants($application, $expected, $sport, $plan);
        }
        foreach (array_keys($plan['initializations']) as $id) {
            $initialization = $this->current('initializations', $id, $connection, $plan, $cache, $budget);
            $this->verifyInitialization($initialization, $sport, $definition, $connection, $plan, $locked, $cache, $budget);
            if ($initialization['kind'] === 'baseline_admission' && $initialization['baseline_kind'] === 'reviewed_legacy_current') {
                $this->retainSelected($sport, $initialization['entity_type'], $initialization['entity_id'], $initialization['evidence']['history'],
                    ['kind' => 'reviewed_baseline', 'initialization_id' => $id, 'initialization_hash' => $plan['initializations'][$id],
                        'source_fingerprint' => $initialization['source_fingerprint']], $selected, $proofs, $plan);
            }
        }
        $this->verifyKnownFinals($connection, $sport, $definition, $plan, $locked, $cache, $budget);
        $ordered = [];
        foreach ($selected as $key => $request) {
            if (! isset($proofs[$key])) {
                $this->refuse('selected_history_unbound', $plan);
            }
            $ordered[$key] = $proofs[$key];
        }

        return $ordered;
    }

    private function retainSelected(string $sport, string $type, int $entityId, array $history, array $lineage, array $selected, array &$proofs, array $plan): void
    {
        $key = $type.':'.$entityId.':'.($history['id'] ?? '');
        if (! isset($selected[$key])) {
            return;
        }
        if (isset($proofs[$key])) {
            $this->refuse('selected_history_ambiguous_linkage', $plan);
        }
        $definition = $this->registry->resolve($sport, $type);
        $proofs[$key] = ['history' => ['table' => (new ($definition['history_model']))->getTable(), 'id' => (int) $history['id'],
            'entity_type' => $type, 'entity_id' => $entityId, 'game_id' => (int) $history['game_id'], 'season' => (int) $history['season'],
            'date' => substr((string) $history[$definition['history_date']], 0, 10),
            'rating' => $this->decimals->rating($history['elo_rating']),
            'delta' => $history['elo_change'] === null ? null : $this->decimals->rating($history['elo_change']),
            'team_id' => $type === 'pitcher' ? (int) $history['team_id'] : null,
            'games_started' => $type === 'pitcher' ? (int) $history['games_started'] : null], 'lineage' => $lineage];
    }

    /** Only matching ancestor windows contribute retroactive known-final dependencies. */
    public function requiresSource(array $plan, Model $game, array $pitching): bool
    {
        foreach ($this->windows($plan, $game, $pitching) as $window) {
            if ($window['through'] === null) {
                return true;
            }
            $date = substr((string) $game->getRawOriginal('game_date'), 0, 10);
            if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) === 1 && $date > substr($window['through'][0], 0, 10)) {
                continue;
            }
            try {
                $key = [(new UtcEloDateTime)->observed($game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'))->format('Y-m-d H:i:s.u'), (int) $game->id];
            } catch (Throwable) {
                return true; // The source validator must refuse unknown chronology, never skip it.
            }
            if ($key <= $window['through']) {
                return true;
            }
        }

        return false;
    }

    private function addWindow(array &$plan, int $season, array $window): void
    {
        $key = $window['entity_type'].':'.$window['entity_id'];
        $old = $plan['ranges'][$season][$key] ?? null;
        if ($old !== null && $old['initialization_id'] !== $window['initialization_id']) {
            $this->refuse('dependency_season_initialization_conflict', $plan);
        }
        // Null is an explicit whole-season dependency of rollover, never an invented event time.
        if ($old === null || ($old['through'] !== null && ($window['through'] === null || $old['through'] < $window['through']))) {
            $plan['ranges'][$season][$key] = $window;
        }
    }

    private function windows(array $plan, Model $game, array $pitching): array
    {
        $keys = ['team:'.$game->home_team_id, 'team:'.$game->away_team_id];
        foreach ($pitching as $row) {
            $keys[] = 'pitcher:'.$row['player_id'];
        }

        return array_intersect_key($plan['ranges'][(int) $game->season] ?? [], array_flip($keys));
    }

    private function verifyKnownFinals(Connection $connection, string $sport, array $definition, array $plan, array $locked, array &$cache, array &$budget): void
    {
        $applications = [];
        foreach (array_keys($plan['applications']) as $id) {
            $application = $this->current('applications', $id, $connection, $plan, $cache, $budget);
            $applications[$application['game_id']] = $id;
        }
        $cutoffs = [];
        foreach ($locked['sources'] as $gameId => $source) {
            $game = $locked['games'][$gameId];
            $key = [$source['played_at_utc'], (int) $gameId];
            foreach ($this->windows($plan, $game, $source['pitching_observations']) as $window) {
                if ($window['through'] !== null && $key > $window['through']) {
                    continue;
                }
                $initialization = $this->current('initializations', $window['initialization_id'], $connection, $plan, $cache, $budget);
                if ($initialization['kind'] === 'baseline_admission' && $initialization['baseline_kind'] === 'reviewed_legacy_current') {
                    $initializationId = $initialization['id'];
                    if (! array_key_exists($initializationId, $cutoffs)) {
                        $head = new SportEloEntityHead;
                        $head->forceFill(['sport' => $sport, 'entity_type' => $window['entity_type'], 'entity_id' => $window['entity_id'], 'calculation_version' => $definition['version']]);
                        $entityDefinition = $this->registry->resolve($sport, $window['entity_type']);
                        $cutoffs[$initializationId] = $this->baselines->cutoff($connection, $head, (int) $game->season, $entityDefinition, $locked);
                    }
                    if ($cutoffs[$initializationId] !== null && $this->baselines->includes($game, $cutoffs[$initializationId])) {
                        continue;
                    }
                }
                if (! isset($applications[$gameId])) {
                    $this->refuse('dependency_unreceipted_ancestor_final', $plan, (int) $gameId);
                }
                $application = $this->current('applications', $applications[$gameId], $connection, $plan, $cache, $budget);
                $effectKey = $window['entity_type'].':'.$window['entity_id'];
                if (! isset($application['source_snapshot']['expected_effects'][$effectKey])) {
                    $this->refuse('dependency_ancestor_participation_changed', $plan, (int) $gameId);
                }
            }
        }
    }

    private function verifyEffect(array $effect, array $application, string $sport, array $definition, Connection $connection, array $plan, array $locked, array &$cache, array &$budget): Model
    {
        $initialization = $this->current('initializations', $this->id($effect['initialization_id']), $connection, $plan, $cache, $budget);
        if ($initialization['entity_type'] !== $effect['entity_type'] || $initialization['entity_id'] !== $effect['entity_id'] || $initialization['season'] !== $application['season']) {
            $this->refuse('dependency_initialization_identity_mismatch', $plan);
        }
        if ($effect['previous_effect_id'] === null) {
            if ($initialization['prior_effect_id'] !== null || $effect['rating_before'] !== $initialization['rating_after']) {
                $this->refuse('dependency_initial_input_mismatch', $plan);
            }
        } else {
            $previous = $this->current('effects', $this->id($effect['previous_effect_id']), $connection, $plan, $cache, $budget);
            $previousApp = $this->current('applications', $this->id($previous['application_id']), $connection, $plan, $cache, $budget);
            if ($previous['entity_type'] !== $effect['entity_type'] || $previous['entity_id'] !== $effect['entity_id']
                || [$previousApp['played_at'], $previousApp['game_id']] >= [$application['played_at'], $application['game_id']]) {
                $this->refuse('dependency_cycle_or_entity_chronology', $plan);
            }
            $input = $previous['initialization_id'] === $effect['initialization_id'] ? $previous['rating_after'] : $initialization['rating_after'];
            if ($effect['rating_before'] !== $input || ($previous['initialization_id'] !== $effect['initialization_id']
                && ($initialization['kind'] !== 'season_rollover' || $initialization['prior_effect_id'] !== $effect['previous_effect_id']))) {
                $this->refuse('dependency_previous_input_mismatch', $plan);
            }
        }
        $entityDefinition = $this->registry->resolve($sport, $effect['entity_type']);
        $historyModel = $entityDefinition['history_model'];
        $history = $historyModel::on($connection->getName())->whereKey($effect['evidence']['history_id'] ?? null)->lockForUpdate()->first();
        if ($history === null || $history->getTable() !== ($effect['evidence']['history_table'] ?? null)
            || (int) $history->game_id !== $application['game_id'] || (int) $history->season !== $application['season']
            || (int) $history->{$entityDefinition['entity_key']} !== $effect['entity_id']
            || $this->decimals->rating($history->getRawOriginal('elo_rating')) !== $effect['rating_after']
            || $this->decimals->rating($history->getRawOriginal('elo_change')) !== ($effect['evidence']['legacy_history_delta'] ?? null)
            || substr((string) $history->getRawOriginal($entityDefinition['history_date']), 0, 10) !== substr((string) ($sport === 'mlb' ? ($application['source_snapshot']['observed_source']['game']['game_date'] ?? '') : $application['played_at']), 0, 10)
            || ($effect['entity_type'] === 'pitcher' && ((int) $history->games_started !== $effect['games_started_after'] || (int) $history->team_id !== $effect['team_id']))) {
            $this->refuse('dependency_effective_history_changed', $plan);
        }
        if ($historyModel::on($connection->getName())->where('game_id', $application['game_id'])->orderBy('id')->lockForUpdate()->limit(3)->get(['id'])->count() !== 2) {
            $this->refuse('dependency_history_pair_incomplete', $plan);
        }

        return $history;
    }

    private function verifyInitialization(array $data, string $sport, array $definition, Connection $connection, array $plan, array $locked, array &$cache, array &$budget): void
    {
        $entityDefinition = $this->registry->resolve($sport, $data['entity_type']);
        if ($data['kind'] === 'baseline_admission') {
            if ($data['prior_effect_id'] !== null || isset($data['evidence']['prior_initialization_id']) || $data['rating_before'] !== $data['rating_after']
                || $data['prior_rating'] !== null || $data['regression_factor'] !== null
                || $data['rating_after'] !== ($data['evidence']['rating'] ?? null)
                || ($data['evidence']['entity_table'] ?? null) !== (new ($entityDefinition['entity_model']))->getTable()
                || (int) ($data['evidence']['entity']['id'] ?? 0) !== $data['entity_id']
                || $data['source_history_table'] !== (new ($entityDefinition['history_model']))->getTable()
                || ! hash_equals($data['source_fingerprint'], $this->hasher->hash($data['evidence']))) {
                $this->refuse('dependency_baseline_incoherent', $plan);
            }
            if ($data['baseline_kind'] === 'declared_default') {
                if ($data['rating_after'] !== $this->decimals->rating($entityDefinition['default']) || $data['source_history_id'] !== null
                    || ($data['evidence']['history'] ?? null) !== null || ($data['evidence']['game'] ?? null) !== null) {
                    $this->refuse('dependency_default_boundary_unknown', $plan);
                }
            } elseif ($data['baseline_kind'] === 'reviewed_legacy_current') {
                $head = new SportEloEntityHead;
                $head->forceFill(['sport' => $sport, 'entity_type' => $data['entity_type'], 'entity_id' => $data['entity_id'], 'calculation_version' => $definition['version']]);
                $cutoff = $this->baselines->cutoff($connection, $head, $data['season'], $entityDefinition, $locked);
                if ($cutoff === null || $cutoff['receipt_id'] !== $data['id'] || trim((string) $data['review_reference']) === '') {
                    $this->refuse('dependency_reviewed_boundary_unknown', $plan);
                }
            } else {
                $this->refuse('dependency_baseline_kind_unknown', $plan);
            }

            return;
        }
        $prior = $this->current('initializations', $this->id($data['evidence']['prior_initialization_id'] ?? null), $connection, $plan, $cache, $budget);
        if ($data['kind'] !== 'season_rollover' || $data['baseline_kind'] !== 'prior_admitted_head'
            || $prior['entity_type'] !== $data['entity_type'] || $prior['entity_id'] !== $data['entity_id'] || $data['season'] !== $prior['season'] + 1
            || ! hash_equals($data['source_fingerprint'], $this->hasher->hash(['evidence' => $data['evidence'], 'factor' => $data['regression_factor']]))) {
            $this->refuse('dependency_rollover_identity_changed', $plan);
        }
        $input = $prior['rating_after'];
        if ($data['prior_effect_id'] !== null) {
            $effect = $this->current('effects', $this->id($data['prior_effect_id']), $connection, $plan, $cache, $budget);
            if ($effect['entity_type'] !== $data['entity_type'] || $effect['entity_id'] !== $data['entity_id'] || $effect['initialization_id'] !== $prior['id']) {
                $this->refuse('dependency_rollover_input_changed', $plan);
            }
            $input = $effect['rating_after'];
        }
        try {
            $factor = $this->decimals->factor($data['regression_factor']);
        } catch (Throwable) {
            $this->refuse('dependency_rollover_factor_invalid', $plan);
        }
        $default = $this->decimals->rating($entityDefinition['default']);
        $after = $this->decimals->rating((int) round((float) $input + (float) $factor * ((float) $default - (float) $input)));
        if ($data['rating_before'] !== $input || $data['prior_rating'] !== $input || $data['rating_after'] !== $after
            || ($data['evidence']['declared_default'] ?? null) !== $default) {
            $this->refuse('dependency_rollover_value_changed', $plan);
        }
    }

    private function verifyParticipants(array $application, array $effects, string $sport, array $plan): void
    {
        $game = $application['source_snapshot']['observed_source']['game'] ?? [];
        $teams = [];
        $pitchers = [];
        foreach ($effects as $key => $effect) {
            if (! is_array($effect) || $key !== ($effect['entity_type'] ?? '').':'.($effect['entity_id'] ?? '')) {
                $this->refuse('dependency_participant_key_changed', $plan);
            }
            if ($effect['entity_type'] === 'team') {
                if ($effect['entity_id'] !== $effect['team_id']) {
                    $this->refuse('dependency_team_identity_changed', $plan);
                }
                $teams[] = $effect['entity_id'];
            } elseif ($effect['entity_type'] === 'pitcher' && $sport === 'mlb') {
                $pitchers[] = ['player_id' => $effect['entity_id'], 'team_id' => $effect['team_id']];
            } else {
                $this->refuse('dependency_entity_type_unknown', $plan);
            }
        }
        sort($teams, SORT_NUMERIC);
        $expected = [$this->id($game['home_team_id'] ?? null), $this->id($game['away_team_id'] ?? null)];
        sort($expected, SORT_NUMERIC);
        if ($teams !== $expected || count(array_unique($teams)) !== 2) {
            $this->refuse('dependency_team_pair_changed', $plan);
        }
        $sourcePitchers = array_map(fn ($row) => ['player_id' => $row['player_id'], 'team_id' => $row['team_id']], $application['source_snapshot']['observed_source']['pitching_observations'] ?? []);
        usort($pitchers, fn ($a, $b) => $a['player_id'] <=> $b['player_id']);
        usort($sourcePitchers, fn ($a, $b) => $a['player_id'] <=> $b['player_id']);
        if ($pitchers !== $sourcePitchers) {
            $this->refuse('dependency_pitching_pair_changed', $plan);
        }
    }

    private function current(string $kind, int $id, Connection $connection, array $plan, array &$cache, array &$budget): array
    {
        if (! isset($plan[$kind][$id])) {
            $this->refuse('dependency_edge_outside_locked_plan', $plan);
        }
        if (isset($cache[$kind][$id])) {
            return $cache[$kind][$id];
        }
        $class = self::MODELS[$kind];
        $this->preflightBytes($connection, (new $class)->getTable(), $kind, [$id], $plan, $budget, true);
        $row = $connection->table((new $class)->getTable())->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            $this->refuse('dependency_receipt_missing', $plan);
        }
        $data = $this->decode($kind, $row, $connection, $plan, $budget);
        if (! hash_equals($plan[$kind][$id], $this->hasher->hash($data))) {
            $this->refuse('dependency_receipt_changed_after_plan', $plan);
        }
        if (count($cache[$kind]) >= 32) {
            unset($cache[$kind][array_key_first($cache[$kind])]);
        }

        return $cache[$kind][$id] = $data;
    }

    /** Refuse oversized stored JSON before transferring/materializing its body. */
    private function preflightBytes(Connection $connection, string $table, string $kind, array $ids, array $plan, array $budget, bool $current = false): void
    {
        $field = $kind === 'applications' ? 'source_snapshot' : 'evidence';
        $quoted = $connection->getQueryGrammar()->wrap($field);
        $length = match ($connection->getDriverName()) {
            'mysql' => 'OCTET_LENGTH('.$quoted.')',
            'sqlite' => 'LENGTH(CAST('.$quoted.' AS BLOB))',
            default => throw new RuntimeException('Unsupported Elo dependency byte preflight connection.'),
        };
        $sizes = $connection->table($table)->whereIn('id', $ids)->orderBy('id')->select('id')->selectRaw($length.' AS receipt_bytes')->when($current, fn ($query) => $query->lockForUpdate())->get();
        if ($sizes->count() !== count($ids)) {
            $this->refuse('dependency_receipt_missing', $plan);
        }
        $bytes = $budget['bytes'];
        foreach ($sizes as $size) {
            if ($size->receipt_bytes === null || (int) $size->receipt_bytes > $plan['policy']['limits']['receipt_bytes']) {
                $this->refuse('dependency_receipt_byte_budget', $plan);
            }
            if (! isset($budget['seen'][$kind.':'.$size->id])) {
                $bytes += (int) $size->receipt_bytes;
            }
            if ($bytes > $plan['policy']['limits']['total_receipt_bytes']) {
                $this->refuse('dependency_total_receipt_byte_budget', $plan);
            }
        }
    }

    private function decode(string $kind, object $row, Connection $connection, array $plan, array &$budget): array
    {
        $id = $this->id($row->id);
        $field = $kind === 'applications' ? 'source_snapshot' : 'evidence';
        $raw = $row->$field ?? null;
        if (! is_string($raw) || strlen($raw) > $plan['policy']['limits']['receipt_bytes']) {
            $this->refuse('dependency_receipt_byte_budget', $plan);
        }
        if (! isset($budget['seen'][$kind.':'.$id])) {
            $budget['seen'][$kind.':'.$id] = true;
            $budget['bytes'] += strlen($raw);
            if ($budget['bytes'] > $plan['policy']['limits']['total_receipt_bytes']) {
                $this->refuse('dependency_total_receipt_byte_budget', $plan);
            }
        }
        $this->jsonStructure($raw, $plan);
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->refuse('dependency_receipt_json_invalid', $plan);
        }
        if (! is_array($decoded)) {
            $this->refuse('dependency_receipt_json_invalid', $plan);
        }
        $class = self::MODELS[$kind];
        $model = (new $class)->newFromBuilder((array) $row, $connection->getName());
        $fields = $kind === 'applications' ? self::APP_FIELDS : ($kind === 'initializations' ? self::INIT_FIELDS : ['application_id', 'entity_type', 'entity_id', 'team_id', 'initialization_id', 'previous_effect_id', 'rating_before', 'rating_after', 'rating_delta', 'games_started_before', 'games_started_after']);
        $data = $model->only(array_values(array_diff($fields, [$field])));
        $data[$field] = $decoded;
        $data['id'] = $id;
        foreach (['application_id', 'initialization_id', 'previous_effect_id', 'prior_effect_id', 'source_history_id', 'team_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $data[$key] = $this->id($data[$key]);
            }
        }
        if ($kind === 'applications') {
            $data['played_at'] = $model->played_at->format('Y-m-d H:i:s.u');
        }
        if ($kind !== 'effects') {
            $payload = array_diff_key($data, ['id' => true]);
            if (! is_string($row->receipt_hash) || ! hash_equals($row->receipt_hash, $this->hasher->hash($payload))) {
                $this->refuse('dependency_receipt_hash_invalid', $plan);
            }
        }

        return $data;
    }

    private function scope(string $kind, array $data, string $sport, array $definition, array $plan): void
    {
        if ($kind !== 'effects' && ($data['sport'] !== $sport || $data['calculation_version'] !== $definition['version'] || $data['configuration_hash'] !== $definition['configuration_hash'])) {
            $this->refuse('dependency_scope_or_version_changed', $plan);
        }
        if ($kind !== 'applications' && (! in_array($data['entity_type'], $sport === 'mlb' ? ['team', 'pitcher'] : ['team'], true) || $this->id($data['entity_id']) < 1)) {
            $this->refuse('dependency_entity_identity_invalid', $plan);
        }
        if ($kind === 'applications' && (($data['source_snapshot']['observed_source']['game']['id'] ?? null) !== $data['game_id']
            || (int) ($data['source_snapshot']['observed_source']['game']['season'] ?? 0) !== $data['season']
            || ($data['source_snapshot']['observed_source']['played_at_utc'] ?? null) !== $data['played_at']
            || ! is_array($data['source_snapshot']['observed_source'] ?? null)
            || ! hash_equals($data['source_fingerprint'], $this->hasher->hash($data['source_snapshot']['observed_source'])))) {
            $this->refuse('dependency_observed_source_hash_invalid', $plan);
        }
    }

    private function expectedEffect(array $data): array
    {
        $fields = ['entity_type', 'entity_id', 'team_id', 'initialization_id', 'previous_effect_id', 'rating_before', 'rating_after', 'rating_delta', 'games_started_before', 'games_started_after'];

        return [...array_intersect_key($data, array_flip($fields)), 'legacy_history_delta' => $data['evidence']['legacy_history_delta'] ?? null];
    }

    private function root(object $head): array
    {
        $result = [];
        foreach (['id', 'initialization_id', 'last_effect_id', 'last_game_id', 'generation', 'season'] as $key) {
            $result[$key] = $head->$key === null ? null : (int) $head->$key;
        }

        return $result;
    }

    private function jsonStructure(string $raw, array $plan): void
    {
        $quoted = false;
        $escaped = false;
        $depth = 0;
        $tokens = 0;
        for ($index = 0, $length = strlen($raw); $index < $length; $index++) {
            $byte = $raw[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($byte === '\\') {
                    $escaped = true;
                } elseif ($byte === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($byte === '"') {
                $quoted = true;

                continue;
            }
            if (str_contains('{}[],:', $byte) && ++$tokens > 2048) {
                $this->refuse('dependency_receipt_structure_budget', $plan);
            }
            if (($byte === '{' || $byte === '[') && ++$depth > 31) {
                $this->refuse('dependency_receipt_structure_budget', $plan);
            }
            if ($byte === '}' || $byte === ']') {
                $depth--;
            }
        }
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Elo dependency has an invalid positive identity.');
        }

        return $id;
    }

    private function refuse(string $reason, array $plan, ?int $game = null): never
    {
        throw new EloAdmissionRefused($reason, ['dependency_applications' => ($plan['considered']['applications'] ?? count($plan['applications'])), 'dependency_effects' => ($plan['considered']['effects'] ?? count($plan['effects'])),
            'dependency_initializations' => ($plan['considered']['initializations'] ?? count($plan['initializations']))], $plan['policy']['limits'], $game === null ? [] : ['game_id' => $game]);
    }
}
