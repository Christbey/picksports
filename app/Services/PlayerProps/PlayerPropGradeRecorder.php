<?php

namespace App\Services\PlayerProps;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\PlayerPropGradeRevision;
use App\Models\Sports\AbstractPlayerProp;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlayerPropGradeRecorder
{
    public const POLICY = 'current-stats-v1';

    public function reconcile(Model $candidate, array $config, string $sport, Closure $derive, bool $dryRun = false): array
    {
        $models = [...array_values($config), PlayerPropGradeRevision::class];
        $connections = array_unique([$candidate->getConnection()->getName(), ...array_map(fn (string $model): string => (new $model)->getConnection()->getName(), $models)]);
        if (count($connections) !== 1) {
            throw new \LogicException('Prop grading requires one source and receipt connection.');
        }

        return $candidate->getConnection()->transaction(function () use ($candidate, $config, $sport, $derive, $dryRun): array {
            // All current stat replacement ingesters take this same game lock.
            // Locking reads deliberately avoid a caller's earlier RR snapshot.
            $game = $config['game_model']::query()->lockForUpdate()->find($candidate->game_id);
            if ($game === null) {
                return ['status' => 'missing_game', 'changed' => false, 'graded' => false];
            }
            $stats = $config['player_stat_model']::query()->where('game_id', $game->id)->orderBy('id')->lockForUpdate()->get();
            $playerRelation = (new $config['player_stat_model'])->player();
            $players = $playerRelation->getRelated()->newQuery()->whereKey($stats->pluck('player_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($stats as $stat) {
                $stat->setRelation('player', $players->get($stat->player_id));
            }
            $prop = $candidate->newQuery()->lockForUpdate()->find($candidate->id);
            if ($prop === null || (int) $prop->game_id !== (int) $game->id) {
                return ['status' => 'identity_changed', 'changed' => false, 'graded' => false];
            }
            $head = PlayerPropGradeRevision::query()->where('prop_table', $prop->getTable())->where('prop_id', $prop->id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            $entry = array_intersect_key($prop->getAttributes(), array_flip(AbstractPlayerProp::FROZEN_ENTRY_FIELDS));
            $hasher = app(CanonicalPayloadHasher::class);
            $entryHash = $hasher->hash($entry);
            if ($head !== null && ! hash_equals($head->entry_hash, $entryHash)) {
                return ['status' => 'entry_changed', 'changed' => false, 'graded' => false];
            }
            $normalize = fn (string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower($name));
            $matchingStats = $stats->filter(function (Model $stat) use ($prop, $normalize): bool {
                if ($prop->player_id) {
                    return (int) $stat->player_id === (int) $prop->player_id;
                }
                $name = $stat->player?->full_name ?? trim(($stat->player?->first_name ?? '').' '.($stat->player?->last_name ?? ''));

                return $normalize($prop->player_name) !== '' && $normalize($name) === $normalize($prop->player_name);
            });
            $semantic = $matchingStats->map(function (Model $stat): array {
                $row = array_diff_key($stat->getAttributes(), array_flip(['id', 'created_at', 'updated_at']));
                $row['player_name'] = $stat->player?->full_name
                    ?? trim(($stat->player?->first_name ?? '').' '.($stat->player?->last_name ?? ''));

                return $row;
            })->sortBy(fn (array $row): string => $hasher->hash($row))->values()->all();
            $evidence = ['game_status' => $game->status, 'home_score' => $game->home_score, 'away_score' => $game->away_score,
                'stats' => $semantic, 'matching_mode' => $prop->player_id ? 'linked_player' : 'unique_normalized_exact_name'];
            $fingerprint = $hasher->hash(['policy' => self::POLICY, ...$evidence]);
            $actual = in_array($game->status, GameData::finalStatuses(), true)
                && $game->home_score !== null && $game->away_score !== null && is_numeric($prop->line)
                ? $derive($prop, $stats) : null;
            $graded = $actual !== null;
            $values = ['actual_value' => $actual, 'hit_over' => $graded && $actual !== (float) $prop->line ? $actual > (float) $prop->line : null,
                'error' => $graded ? abs($actual - (float) $prop->line) : null];
            $previous = $this->projection($prop);
            $state = $graded ? 'graded' : 'pending';
            $same = $this->sameValues($previous, $values);
            $matchingHead = $head !== null && hash_equals($head->stat_fingerprint, $fingerprint)
                && $head->state === $state && $same;
            $knownTimestamp = $matchingHead && $graded && filled(data_get($head->projection, 'graded_at'))
                ? CarbonImmutable::parse($head->projection['graded_at']) : null;
            $timestampConsistent = $graded
                ? $prop->graded_at !== null && $knownTimestamp !== null && $prop->graded_at->equalTo($knownTimestamp)
                : $prop->graded_at === null;
            if ($matchingHead && $timestampConsistent) {
                return ['status' => 'unchanged', 'changed' => false, 'graded' => false];
            }
            if (! $graded && $head === null && $prop->graded_at === null) {
                return ['status' => 'pending', 'changed' => false, 'graded' => false];
            }
            $values['graded_at'] = $graded
                ? ($matchingHead ? ($knownTimestamp ?? now()) : ($same && $prop->graded_at !== null ? $prop->graded_at : now()))
                : null;
            $timestampRepair = $matchingHead && ! $timestampConsistent;
            $reason = $timestampRepair
                ? ($graded ? ($knownTimestamp !== null ? 'timestamp_repaired_from_receipt' : 'timestamp_repaired_local_observation') : 'pending_timestamp_cleared')
                : ($head === null ? ($previous['graded_at'] !== null ? 'legacy_grade_first_observation' : 'initial_grade')
                    : ($graded ? 'current_stats_reconciled' : 'current_stats_insufficient'));
            $result = ['status' => $state, 'changed' => true, 'graded' => $graded,
                'actual_value' => $actual, 'hit_over' => $values['hit_over'], 'error' => $values['error']];
            if ($dryRun) {
                return $result;
            }
            $prop->update($values);
            PlayerPropGradeRevision::query()->create([
                explode('_', $prop->getTable())[0].'_prop_id' => $prop->id,
                'sport' => $sport, 'prop_table' => $prop->getTable(), 'prop_id' => $prop->id, 'game_id' => $game->id,
                'revision' => ($head?->revision ?? 0) + 1, 'policy_version' => self::POLICY, 'entry_hash' => $entryHash,
                'stat_fingerprint' => $fingerprint, 'state' => $state,
                'reason' => $reason,
                'entry_snapshot' => $entry, 'stat_snapshot' => [...$evidence,
                    'observed_stat_ids' => $matchingStats->modelKeys(), 'ordering' => 'local_locked_observation_not_provider_revision'],
                'previous_projection' => $previous, 'projection' => $this->projection($prop), 'created_at' => now(),
            ]);

            DB::afterCommit(function (): void {
                try {
                    app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
                } catch (Throwable $exception) {
                    try {
                        report($exception);
                    } catch (Throwable) {
                    }
                }
            });

            return $result;
        });
    }

    private function projection(Model $prop): array
    {
        return ['actual_value' => $prop->actual_value === null ? null : (float) $prop->actual_value,
            'hit_over' => $prop->hit_over, 'error' => $prop->error === null ? null : (float) $prop->error,
            'graded_at' => $prop->graded_at?->toIso8601String()];
    }

    private function sameValues(array $previous, array $next): bool
    {
        foreach (['actual_value', 'error'] as $key) {
            if (($previous[$key] === null) !== ($next[$key] === null)
                || ($previous[$key] !== null && abs((float) $previous[$key] - (float) $next[$key]) > 0.000001)) {
                return false;
            }
        }

        return $previous['hit_over'] === $next['hit_over'];
    }
}
