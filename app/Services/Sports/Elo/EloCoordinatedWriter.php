<?php

namespace App\Services\Sports\Elo;

use App\Actions\MLB\CalculateElo as MlbCalculator;
use App\Actions\Sports\AbstractEloCalculator;
use App\Models\SportEloApplication;
use App\Models\SportEloApplicationEffect;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class EloCoordinatedWriter
{
    public function __construct(
        private readonly EloSportRegistry $registry,
        private readonly EloWriterProtocol $protocol,
        private readonly EloFixedScale $decimals,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly EloSourceContext $sources,
        private readonly EloEligibilityPolicy $eligibility,
    ) {}

    public function execute(string $sport, Model $caller, AbstractEloCalculator $calculator): array
    {
        $definition = $this->registry->resolve($sport, 'team');
        $this->protocol->requireCapability($sport);
        if (! $caller->exists || get_class($caller) !== $definition['game_model']) {
            throw new RuntimeException('Elo publication requires a persisted game of the exact sport.');
        }
        $connection = $caller->getConnection();

        return $connection->transaction(function () use ($sport, $caller, $calculator, $definition, $connection): array {
            $this->protocol->lockGate($connection, $sport, $definition);
            $name = $connection->getName();
            $this->sources->beginObservation();
            $preview = $definition['game_model']::on($name)->whereKey($caller->getKey())->firstOrFail(['id', 'season', 'season_type', 'home_team_id', 'away_team_id']);
            if ($this->eligibility->classify($sport, $preview->getRawOriginal('season_type')) === 'excluded') {
                // This no-write branch locks only its own event. A changed classification must retry
                // rather than locking an earlier source set after this later event lock.
                $excluded = $definition['game_model']::on($name)->whereKey($caller->getKey())->lockForUpdate()->firstOrFail(['id', 'season_type']);
                if ($this->eligibility->classify($sport, $excluded->getRawOriginal('season_type')) !== 'excluded') {
                    $this->sources->refuse('eligibility_changed_outside_planned_sources');
                }

                return $this->skipped($sport, 'excluded_season_type');
            }

            $previewPitching = $sport === 'mlb' ? $this->sources->pitchingSource($connection, $preview, false) : [];
            $participants = ['team' => [(int) $preview->home_team_id, (int) $preview->away_team_id]];
            if ($previewPitching !== []) {
                $participants['pitcher'] = array_column($previewPitching, 'player_id');
            }
            $locked = $this->sources->lockGames($connection, $sport, $definition, $participants, [(int) $caller->id], (int) $preview->season, [(int) $preview->season]);
            $game = $locked['games'][$caller->id];
            $classification = $this->eligibility->classify($sport, $game->getRawOriginal('season_type'));
            if ($classification === 'unknown') {
                $this->sources->refuse('unknown_final_season_type');
            }
            if ($classification === 'excluded') {
                return $this->skipped($sport, 'excluded_season_type');
            }
            $source = $locked['sources'][$caller->id];
            $playedAt = EloSourceClock::observed($game);
            $pitcherSource = $source['pitching_observations'];
            $teams = $definition['entity_model']::on($name)->whereIn('id', [$game->home_team_id, $game->away_team_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($teams->count() !== 2) {
                throw new RuntimeException('Elo participants are unavailable.');
            }
            $entities = [];
            foreach ([$game->home_team_id, $game->away_team_id] as $id) {
                $entities['team:'.$id] = ['type' => 'team', 'entity' => $teams[$id], 'team_id' => (int) $id, 'definition' => $definition];
            }
            if ($pitcherSource !== []) {
                $pitcherDefinition = $this->registry->resolve($sport, 'pitcher');
                $ids = array_column($pitcherSource, 'player_id');
                $pitchers = $pitcherDefinition['entity_model']::on($name)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($pitchers->count() !== 2 || $pitchers->contains(fn ($pitcher) => ! in_array($pitcher->position, ['P', 'SP', 'RP'], true))) {
                    throw new RuntimeException('Elo pitching participants are unavailable or ambiguous.');
                }
                foreach ($pitcherSource as $row) {
                    $entities['pitcher:'.$row['player_id']] = ['type' => 'pitcher', 'entity' => $pitchers[$row['player_id']],
                        'team_id' => $row['team_id'], 'definition' => $pitcherDefinition];
                }
            }
            // All entities are locked before heads; the stable gate serializes every supported writer.
            uasort($entities, fn (array $left, array $right): int => (($left['type'] === 'team' ? 0 : 1) <=> ($right['type'] === 'team' ? 0 : 1))
                    ?: ((int) $left['entity']->id <=> (int) $right['entity']->id));
            $sourceHash = $this->hasher->hash($source);
            $application = SportEloApplication::on($name)->where(['sport' => $sport, 'game_id' => $game->id,
                'calculation_version' => $definition['version']])->lockForUpdate()->first();
            if ($application !== null) {
                $currentHeads = [];
                foreach ($entities as $item) {
                    $head = $this->protocol->lockHead($connection, $sport, $item['type'], $item['entity'], $item['definition']);
                    $this->sources->verifyHead($connection, $head, $locked);
                    $currentHeads[] = $head;
                }
                $this->sources->verifyDependencies($connection, $sport, $definition, $locked, $currentHeads, $application);

                return $this->reuse($connection, $application, $sourceHash, $definition, $entities);
            }
            $cutoffCoverage = [];
            foreach ($entities as $key => &$item) {
                $head = $this->protocol->lockHead($connection, $sport, $item['type'], $item['entity'], $item['definition']);
                $this->sources->verifyHead($connection, $head, $locked);
                $item['head'] = $head;
                $item['reviewed_cutoff'] = $this->sources->reviewedCutoff($connection, $head, (int) $game->season, $item['definition'], $locked);
                $cutoffCoverage[$key] = $this->sources->beforeCutoff($game, $item['reviewed_cutoff']);
            }
            unset($item);
            if (in_array(true, $cutoffCoverage, true)) {
                if (in_array(false, $cutoffCoverage, true)) {
                    $this->sources->refuse('one_sided_reviewed_baseline_cutoff');
                }

                return [...$this->skipped($sport, 'before_reviewed_baseline_cutoff'),
                    'reviewed_baseline_ids' => array_values(array_unique(array_map(fn ($item): int => $item['reviewed_cutoff']['receipt_id'], $entities)))];
            }
            $this->requireNoLegacyGameHistory($connection, $entities, (int) $game->id);
            foreach ($entities as $item) {
                $head = $item['head'];
                if ($head->season !== (int) $game->season || ($head->last_played_at !== null
                    && ($playedAt->lessThan($head->last_played_at) || ($playedAt->equalTo($head->last_played_at) && (int) $game->id <= (int) $head->last_game_id)))) {
                    throw new RuntimeException('Elo season or chronological order requires reviewed rollover or reconstruction.');
                }
                $this->sources->verifyPending($connection, $head, $item['definition'], $locked, $game);
            }
            $this->sources->verifyDependencies($connection, $sport, $definition, $locked, array_map(fn ($item) => $item['head'], $entities));
            $changes = $calculator->previewChanges($game, (float) $entities['team:'.$game->home_team_id]['head']->rating,
                (float) $entities['team:'.$game->away_team_id]['head']->rating);
            $result = $sport === 'mlb' ? ['home_team_change' => $changes['home_change'], 'away_team_change' => $changes['away_change'],
                'home_team_new_elo' => $changes['home_new_elo'], 'away_team_new_elo' => $changes['away_new_elo'],
                'home_pitcher_change' => 0, 'away_pitcher_change' => 0, 'home_pitcher_new_elo' => null, 'away_pitcher_new_elo' => null, 'skipped' => false]
                : [...$changes, 'skipped' => false];
            $outputs = ['team:'.$game->home_team_id => [$changes['home_new_elo'], $changes['home_change']],
                'team:'.$game->away_team_id => [$changes['away_new_elo'], $changes['away_change']]];
            if ($pitcherSource !== []) {
                if (! $calculator instanceof MlbCalculator) {
                    throw new RuntimeException('The MLB pitcher adapter is unavailable.');
                }
                $homePitcher = $pitcherSource[0]['player_id'];
                $awayPitcher = $pitcherSource[1]['player_id'];
                $pitcherChanges = $calculator->previewPitcherChanges($game, (float) $entities['pitcher:'.$homePitcher]['head']->rating,
                    (float) $entities['pitcher:'.$awayPitcher]['head']->rating);
                $outputs['pitcher:'.$homePitcher] = [$pitcherChanges['home_new_elo'], $pitcherChanges['home_change']];
                $outputs['pitcher:'.$awayPitcher] = [$pitcherChanges['away_new_elo'], $pitcherChanges['away_change']];
                $result = [...$result, 'home_pitcher_change' => $pitcherChanges['home_change'], 'away_pitcher_change' => $pitcherChanges['away_change'],
                    'home_pitcher_new_elo' => $pitcherChanges['home_new_elo'], 'away_pitcher_new_elo' => $pitcherChanges['away_new_elo']];
            }
            $expectedEffects = [];
            foreach ($entities as $key => $item) {
                [$after, $delta] = $outputs[$key];
                $head = $item['head'];
                $countBefore = null;
                if ($item['type'] === 'pitcher') {
                    $historyModel = $item['definition']['history_model'];
                    $priorStarts = $historyModel::on($name)->where('player_id', $item['entity']->id)->where('season', $game->season)
                        ->orderBy('id')->lockForUpdate()->limit(10001)->get(['id']);
                    if ($priorStarts->count() > 10000) {
                        throw new RuntimeException('MLB pitcher history budget exceeded.');
                    }
                    $countBefore = $priorStarts->count();
                }
                $expectedEffects[$key] = ['entity_type' => $item['type'], 'entity_id' => (int) $item['entity']->id,
                    'team_id' => $item['team_id'], 'initialization_id' => (int) $head->initialization_id,
                    'previous_effect_id' => $head->last_effect_id === null ? $head->getRelation('integrityInitialization')->prior_effect_id : (int) $head->last_effect_id,
                    'rating_before' => $head->rating, 'rating_after' => $this->decimals->rating($after),
                    'rating_delta' => $this->exactDifference($this->decimals->rating($after), $head->rating),
                    'games_started_before' => $countBefore, 'games_started_after' => $countBefore === null ? null : $countBefore + 1,
                    'legacy_history_delta' => $this->decimals->rating($delta)];
            }
            $payload = ['sport' => $sport, 'game_id' => (int) $game->id, 'season' => (int) $game->season,
                'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
                'source_fingerprint' => $sourceHash, 'played_at' => $playedAt->format('Y-m-d H:i:s.u'),
                'source_snapshot' => ['observed_source' => $source, 'result' => $result, 'expected_effects' => $expectedEffects]];
            $application = SportEloApplication::on($name)->create([...$payload, 'receipt_hash' => $this->hasher->hash($payload), 'recorded_at' => now()]);
            foreach ($entities as $key => $item) {
                [$after, $delta] = $outputs[$key];
                $head = $item['head'];
                $historyModel = $item['definition']['history_model'];
                $history = ['game_id' => $game->id, $item['definition']['entity_key'] => $item['entity']->id,
                    'season' => $game->season, $item['definition']['history_date'] => $game->getRawOriginal('game_date'),
                    'elo_rating' => $after, 'elo_change' => $delta];
                $expected = $expectedEffects[$key];
                if ($item['type'] === 'pitcher') {
                    $history += ['team_id' => $item['team_id'], 'games_started' => $expected['games_started_after']];
                } elseif ($sport === 'nfl') {
                    $history['week'] = $game->week;
                }
                $row = $historyModel::on($name)->create($history);
                $effect = SportEloApplicationEffect::on($name)->create([...array_diff_key($expected, ['legacy_history_delta' => true]),
                    'application_id' => $application->id,
                    'evidence' => ['history_table' => $row->getTable(), 'history_id' => $row->id,
                        'legacy_history_delta' => $expected['legacy_history_delta']], 'recorded_at' => now()]);
                $item['entity']->forceFill(['elo_rating' => $after])->save();
                $head->forceFill(['last_effect_id' => $effect->id, 'rating' => $this->decimals->rating($after), 'source_fingerprint' => $sourceHash,
                    'last_played_at' => $playedAt, 'last_game_id' => $game->id, 'generation' => $head->generation + 1])->save();
            }

            return $result;
        }, 3);
    }

    private function skipped(string $sport, string $reason): array
    {
        return $sport === 'mlb' ? ['home_team_change' => 0, 'away_team_change' => 0,
            'home_pitcher_change' => 0, 'away_pitcher_change' => 0, 'skipped' => true, 'reason' => $reason]
            : ['home_change' => 0, 'away_change' => 0, 'skipped' => true, 'reason' => $reason];
    }

    private function effectMatches(SportEloApplicationEffect $effect, array $expected): bool
    {
        $actual = $effect->only(['entity_type', 'entity_id', 'team_id', 'initialization_id', 'previous_effect_id',
            'rating_before', 'rating_after', 'rating_delta', 'games_started_before', 'games_started_after']);
        foreach (['team_id', 'initialization_id', 'previous_effect_id'] as $key) {
            $actual[$key] = $actual[$key] === null ? null : (int) $actual[$key];
        }
        $actual['legacy_history_delta'] = $effect->evidence['legacy_history_delta'] ?? null;

        return $this->hasher->hash($actual) === $this->hasher->hash($expected);
    }

    private function exactDifference(string $after, string $before): string
    {
        $difference = (int) str_replace('.', '', $after) - (int) str_replace('.', '', $before);
        $absolute = str_pad((string) abs($difference), 5, '0', STR_PAD_LEFT);

        return $this->decimals->rating(($difference < 0 ? '-' : '').substr($absolute, 0, -4).'.'.substr($absolute, -4));
    }

    private function requireNoLegacyGameHistory(Connection $connection, array $entities, int $gameId): void
    {
        foreach (array_unique(array_column(array_column($entities, 'definition'), 'history_model')) as $historyModel) {
            if ($historyModel::on($connection->getName())->where('game_id', $gameId)->lockForUpdate()->first() !== null) {
                throw new RuntimeException('Existing unreceipted Elo history requires reviewed admission or reconstruction.');
            }
        }
    }

    private function reuse(Connection $connection, SportEloApplication $application, string $sourceHash, array $definition, array $entities): array
    {
        $payload = $application->only(['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint', 'source_snapshot']);
        $payload['played_at'] = $application->played_at->format('Y-m-d H:i:s.u');
        if ($application->configuration_hash !== $definition['configuration_hash'] || $application->source_fingerprint !== $sourceHash
            || ! hash_equals($application->receipt_hash, $this->hasher->hash($payload))) {
            throw new RuntimeException('Elo source/configuration correction requires reviewed reconstruction; stored receipts are retained.');
        }
        $effects = SportEloApplicationEffect::on($connection->getName())->where('application_id', $application->id)->orderBy('id')->lockForUpdate()->limit(5)->get();
        if ($effects->count() !== count($entities)) {
            throw new RuntimeException('Elo receipt is incomplete.');
        }
        foreach (array_unique(array_column(array_column($entities, 'definition'), 'history_model')) as $historyModel) {
            if ($historyModel::on($connection->getName())->where('game_id', $application->game_id)->orderBy('id')->lockForUpdate()->limit(3)->get(['id'])->count() !== 2) {
                throw new RuntimeException('Elo effective history does not contain a complete unique pair.');
            }
        }
        foreach ($effects as $effect) {
            $key = $effect->entity_type.':'.$effect->entity_id;
            $item = $entities[$key] ?? null;
            if ($item === null) {
                throw new RuntimeException('Elo receipt participants conflict.');
            }
            $expected = $application->source_snapshot['expected_effects'][$key] ?? null;
            if (! is_array($expected) || ! $this->effectMatches($effect, $expected)) {
                throw new RuntimeException('Elo effect differs from its hashed application receipt.');
            }
            $history = $item['definition']['history_model']::on($connection->getName())->whereKey($effect->evidence['history_id'] ?? null)->lockForUpdate()->first();
            if ($history === null || $history->getTable() !== ($effect->evidence['history_table'] ?? null)
                || (int) $history->season !== $application->season
                || (int) $history->game_id !== $application->game_id
                || (int) $history->{$item['definition']['entity_key']} !== $effect->entity_id
                || $this->decimals->rating($history->getRawOriginal('elo_rating')) !== $effect->rating_after
                || $this->decimals->rating($history->getRawOriginal('elo_change')) !== ($effect->evidence['legacy_history_delta'] ?? null)
                || ($item['type'] === 'pitcher' && ((int) $history->games_started !== $effect->games_started_after || (int) $history->team_id !== (int) $effect->team_id))) {
                throw new RuntimeException('Elo effective history differs from its immutable receipt.');
            }
        }

        return [...$application->source_snapshot['result'], 'skipped' => true];
    }
}
