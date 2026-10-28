<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NflSignalObservation;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use App\Support\NflTeamAlignment;
use Illuminate\Support\Facades\DB;

/** Exploratory associations from the existing observation/grade ledger; never a promotion gate. */
class NflEvidenceLeaderboard
{
    public const MIN_GAMES = 30;

    public function report(array $filters = []): array
    {
        $season = now()->month < 3 ? now()->year - 1 : now()->year;
        $from = (int) ($filters['from_season'] ?? $season);
        $to = (int) ($filters['to_season'] ?? $season);
        $validationWeek = (int) ($filters['validation_from_week'] ?? 5);
        $cohort = $filters['cohort'] ?? 'all';
        $version = $filters['model_version'] ?? NflModelVersion::current();
        $scope = NflSignalObservation::query()
            ->join('prediction_feature_snapshots as snapshot', 'snapshot.id', '=', 'nfl_signal_observations.prediction_feature_snapshot_id')
            ->join('model_runs as run', 'run.id', '=', 'snapshot.model_run_id')
            ->whereColumn('run.config_hash', 'nfl_signal_observations.config_hash')
            ->whereColumn('snapshot.model_run_id', 'nfl_signal_observations.model_run_id')
            ->join('nfl_games as game', 'game.id', '=', 'nfl_signal_observations.game_id')
            ->whereBetween('nfl_signal_observations.season', [$from, $to])
            ->whereIn('game.season_type', ['2', 'regular'])->where('snapshot.sport', 'nfl')
            ->where('snapshot.prediction_table', 'nfl_predictions')
            ->whereColumn('snapshot.game_id', 'game.id')
            ->whereColumn('snapshot.model_version', 'nfl_signal_observations.model_version')
            ->whereColumn('snapshot.feature_version', 'nfl_signal_observations.feature_version')
            ->whereColumn('snapshot.blend_version', 'nfl_signal_observations.blend_version')
            ->whereColumn('game.season', 'nfl_signal_observations.season')
            ->where('snapshot.generated_at', '<=', now())
            ->where('nfl_signal_observations.pregame_safe', true)->where('snapshot.pregame_safe', true)
            ->where('nfl_signal_observations.availability_status', 'observed_pregame')
            ->where('snapshot.availability_status', 'observed_pregame')
            ->whereColumn('nfl_signal_observations.observed_at', 'snapshot.generated_at')
            ->whereColumn('nfl_signal_observations.game_start_at', 'snapshot.game_start_at')
            ->whereColumn('snapshot.generated_at', '<', 'snapshot.game_start_at')
            ->whereColumn('snapshot.features_available_at', '<=', 'snapshot.generated_at')
            ->where('nfl_signal_observations.model_version', $version)
            ->when(! empty($filters['signal_type']), fn ($q) => $q->where('nfl_signal_observations.signal_type', $filters['signal_type']))
            ->when(! empty($filters['signal_key']), fn ($q) => $q->where('nfl_signal_observations.signal_key', $filters['signal_key']));
        $ranked = (clone $scope)->select('nfl_signal_observations.id')->selectRaw(
            'ROW_NUMBER() OVER (PARTITION BY nfl_signal_observations.game_id, signal_type, signal_key,
            nfl_signal_observations.model_version, nfl_signal_observations.feature_version,
            nfl_signal_observations.blend_version, nfl_signal_observations.config_hash, definition_hash, direction
            ORDER BY observed_at DESC, nfl_signal_observations.id DESC) as occurrence_rank'
        );
        $ids = DB::query()->fromSub($ranked->toBase(), 'ranked')->where('occurrence_rank', 1)->select('id');
        $groups = [];
        $selected = $excluded = 0;
        NflSignalObservation::query()->whereIn('id', $ids)
            ->with(['featureSnapshot:id,features,market_context,model_metadata', 'game.homeTeam', 'game.awayTeam', 'game.sportEvent',
                'grades' => fn ($q) => $q->where('evaluation_source', 'outcome')])
            ->chunkById(250, function ($observations) use (&$groups, &$selected, &$excluded, $cohort) {
                foreach ($observations as $o) {
                    $game = $o->game;
                    $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
                    if ($kickoff === null || ! $kickoff->eq($o->game_start_at)) {
                        $excluded++;

                        continue;
                    }
                    $kind = $this->cohort($game);
                    if ($cohort !== 'all' && $kind !== $cohort) {
                        continue;
                    }
                    $selected++;
                    $state = $o->signal_type === 'matchup_condition' ? data_get($o->signal_payload, 'state', 'unavailable') : 'triggered';
                    $context = data_get($o->featureSnapshot?->features, 'evidence.context', ['cohort' => $kind]);
                    $stratum = app(NflEvidenceComparison::class)->stratum($context, $o->featureSnapshot?->market_context ?? [], $o->week);
                    foreach (['winner', 'spread', 'total'] as $market) {
                        $key = implode('|', [$o->signal_type, $o->signal_key, $o->model_version,
                            $o->feature_version, $o->blend_version, $o->config_hash, $o->definition_hash, $o->direction ?? 'context', $market]);
                        $family = $key;
                        $key .= '|'.$state;
                        $groups[$key] ??= ['signal_type' => $o->signal_type, 'signal_key' => $o->signal_key,
                            'label' => $o->label ?: $o->signal_key, 'model_version' => $o->model_version,
                            'feature_version' => $o->feature_version, 'blend_version' => $o->blend_version,
                            'definition_hash' => $o->definition_hash, 'config_hash' => $o->config_hash, 'direction' => $o->direction,
                            'market' => $market, 'condition_state' => $state, 'comparison_family' => $family, 'games' => []];
                        $grade = $o->grades->firstWhere('market_type', $market);
                        $final = $game->status === 'STATUS_FINAL' && $game->home_score !== null && $game->away_score !== null;
                        $groups[$key]['games'][$o->game_id] = ['season' => $o->season, 'week' => $o->week,
                            'cohort' => $kind, 'stratum' => $stratum,
                            'comparison_loss' => $final ? ($market === 'winner' ? $grade?->brier_score : $grade?->absolute_error) : null,
                            'result' => $final ? $grade?->result_status : null,
                            'lift' => $final ? ($market === 'winner' ? $grade?->calibration_lift : $grade?->error_lift) : null,
                            'error' => $final ? $grade?->absolute_error : null,
                            'brier' => $final ? $grade?->brier_score : null,
                            'basis' => $grade?->metadata['direction_source'] ?? 'unknown'];
                    }
                }
            });
        $rows = [];
        foreach ($groups as $group) {
            $games = $group['games'];
            unset($group['games']);
            $discovery = array_filter($games, fn ($g) => $g['week'] !== null && $g['week'] < $validationWeek);
            $later = array_filter($games, fn ($g) => $g['week'] !== null && $g['week'] >= $validationWeek);
            $metrics = $this->metrics($games);
            $weeks = [];
            foreach ($games as $g) {
                $weeks[$g['season'].':'.($g['week'] ?? 'unknown')][] = $g;
            }
            $windows = [];
            foreach ($weeks as $week => $samples) {
                $windows[] = ['window' => $week, ...$this->metrics($samples)];
            }
            // The same observed game set is shared evidence, not independent corroboration.
            $ids = array_keys(array_filter($games, fn ($g) => in_array($g['result'], ['win', 'loss'], true)));
            sort($ids);
            $comparison = $group['condition_state'] === 'matched'
                ? app(NflEvidenceComparison::class)->compare($games, $groups[$group['comparison_family'].'|not_matched']['games'] ?? []) : null;
            unset($group['comparison_family']);
            $rows[] = [...$group, 'control_comparison' => $comparison, '_game_ids' => $ids, ...$metrics, 'discovery' => $this->metrics($discovery),
                'later' => $this->metrics($later), 'weeks' => $windows,
                'cohorts' => collect($games)->groupBy('cohort')->map(fn ($g) => $this->metrics($g->all()))->all(),
                'direction_basis' => array_values(array_unique(array_column($games, 'basis'))),
                'status' => $metrics['sample'] < self::MIN_GAMES ? 'insufficient_evidence' : 'exploratory_only',
                'overlap_fingerprint' => $ids === [] ? null : hash('sha256', implode(',', $ids)),
                'validated' => false, 'bet_eligible' => false];
        }
        $fingerprints = collect($rows)->filter(fn ($r) => $r['overlap_fingerprint'] !== null)
            ->groupBy(fn ($r) => $r['market'].'|'.$r['model_version'].'|'.$r['overlap_fingerprint']);
        foreach ($rows as &$row) {
            $peers = $fingerprints->get($row['market'].'|'.$row['model_version'].'|'.$row['overlap_fingerprint'], collect());
            $row['identical_sample_signals'] = $peers->pluck('signal_key')->unique()->reject(fn ($key) => $key === $row['signal_key'])->take(10)->values()->all();
            unset($row['overlap_fingerprint']);
        }
        unset($row);
        $overlapPool = collect($rows)->filter(fn ($r) => $r['sample'] >= 5)->sortByDesc('sample')->take(300)->values();
        foreach ($rows as &$row) {
            $partial = [];
            if ($row['sample'] >= 5) {
                foreach ($overlapPool as $peer) {
                    if ($peer['market'] !== $row['market'] || $peer['model_version'] !== $row['model_version'] || $peer['config_hash'] !== $row['config_hash'] || $peer['signal_key'] === $row['signal_key']) {
                        continue;
                    }
                    $shared = count(array_intersect($row['_game_ids'], $peer['_game_ids']));
                    $union = count(array_unique([...$row['_game_ids'], ...$peer['_game_ids']]));
                    if ($shared >= 5 && $union && $shared / $union >= .5) {
                        $partial[] = ['signal_key' => $peer['signal_key'], 'shared_games' => $shared, 'jaccard' => round($shared / $union, 4)];
                    }
                }
            }
            $row['partial_overlap'] = collect($partial)->sortByDesc('jaccard')->take(5)->values()->all();
            unset($row['_game_ids']);
        }
        unset($row);
        usort($rows, fn ($a, $b) => [$b['sample'] >= self::MIN_GAMES, $b['wilson_lower'] ?? -1, $b['sample'], $b['signal_key']]
            <=> [$a['sample'] >= self::MIN_GAMES, $a['wilson_lower'] ?? -1, $a['sample'], $a['signal_key']]);

        return ['generated_at' => now()->toIso8601String(), 'scope' => ['from_season' => $from, 'to_season' => $to,
            'model_version' => $version, 'cohort' => $cohort, 'validation_from_week' => $validationWeek],
            'minimum_games' => self::MIN_GAMES, 'selected_observations' => $selected,
            'excluded_kickoff_mismatch' => $excluded, 'total_rows' => count($rows),
            'rows' => $rows, 'overlap_search_limit' => 300, 'inventory' => $this->inventory(),
            'limitations' => ['Ranks are exploratory associations, not isolated signal contributions or betting approvals.',
                'Winner lift compares the stored model baseline; spread and total lift compare stored market lines. Positive is better.',
                'Week split is descriptive, not an untouched holdout or multiple-testing correction. No signal is validated automatically.',
                'Overall hit-rate ranking is exploratory. Control comparisons use coarse strata and do not establish causality; overlap search is bounded to 300 largest samples.',
                'Wilson intervals assume independent games. Weekly stability and identical-sample flags do not remove all correlations.',
                'Missing grades remain unavailable; reconstructions and post-kickoff observations are excluded.'],
            'prediction_effect' => false];
    }

    public function cohort(Game $game): string
    {
        if ($game->season < 2002) {
            return 'unknown';
        }
        $aliases = ['SD' => 'LAC', 'STL' => 'LAR', 'OAK' => 'LV'];
        $teams = [];
        foreach ([$game->homeTeam?->abbreviation, $game->awayTeam?->abbreviation] as $abbreviation) {
            $name = strtoupper((string) $abbreviation);
            $teams[] = NflTeamAlignment::TEAMS[$aliases[$name] ?? $name] ?? null;
        }
        [$home, $away] = $teams;
        if ($home === null || $away === null) {
            return 'unknown';
        }
        if ($home['conference'] !== $away['conference']) {
            return 'interconference';
        }

        return $home['division'] === $away['division'] ? 'divisional' : 'conference_non_divisional';
    }

    private function metrics(array $games): array
    {
        $wins = count(array_filter($games, fn ($g) => $g['result'] === 'win'));
        $losses = count(array_filter($games, fn ($g) => $g['result'] === 'loss'));
        $pushes = count(array_filter($games, fn ($g) => $g['result'] === 'push'));
        $n = $wins + $losses;
        $rate = $n ? $wins / $n : null;
        $lower = $upper = null;
        if ($n) {
            $z = 1.95996398454;
            $denom = 1 + $z ** 2 / $n;
            $center = ($rate + $z ** 2 / (2 * $n)) / $denom;
            $half = $z * sqrt($rate * (1 - $rate) / $n + $z ** 2 / (4 * $n ** 2)) / $denom;
            $lower = round(max(0, $center - $half), 6);
            $upper = round(min(1, $center + $half), 6);
        }
        $graded = array_filter($games, fn ($g) => in_array($g['result'], ['win', 'loss', 'push', 'no_pick'], true));
        $paired = array_values(array_filter(array_column($graded, 'lift'), fn ($v) => is_numeric($v) && is_finite((float) $v)));
        $average = fn ($values) => $values === [] ? null : round(array_sum($values) / count($values), 6);

        return ['games' => count($games), 'sample' => $n, 'wins' => $wins, 'losses' => $losses,
            'pushes' => $pushes, 'no_pick' => count(array_filter($games, fn ($g) => $g['result'] === 'no_pick')), 'ungraded' => count($games) - count($graded), 'hit_rate' => $rate === null ? null : round($rate, 6),
            'wilson_lower' => $lower, 'wilson_upper' => $upper, 'paired_baseline_sample' => count($paired),
            'baseline_lift' => $average($paired),
            'mean_absolute_error' => $average(array_values(array_filter(array_column($graded, 'error'), is_numeric(...)))),
            'brier' => $average(array_values(array_filter(array_column($graded, 'brier'), is_numeric(...))))];
    }

    private function inventory(): array
    {
        return [
            ['evidence' => 'Reason codes, risk flags, matched/pass rules and combinations', 'collection' => 'Frozen prediction metadata and immutable signal observations', 'grading' => 'Winner, spread, total outcomes; exact settlements in existing report', 'gap' => 'Usually conditional full-model performance, not component ablation or causality.'],
            ['evidence' => 'Division and conference context', 'collection' => 'Derived from franchise alignment for seasons 2002 onward; earlier/unknown teams remain unknown', 'grading' => 'Separate descriptive cohorts in this leaderboard', 'gap' => 'Context has no independent directional pick.'],
            ['evidence' => 'EPA, pressure, coverage, personnel and position matchup catalog', 'collection' => 'Available in current matchup-page calculations where coverage permits', 'grading' => 'Prospective compact captures feed the existing observation and grading ledger', 'gap' => 'Only captures after deployment count; missing or unsupported source coverage remains explicit. No reconstructed backfill.'],
            ['evidence' => 'Raw model inputs, neutral and absent conditions', 'collection' => 'Raw inputs remain linked to source snapshots; new captures freeze matched/absent states for the known atomic signal universe', 'grading' => 'Frozen matched versus not-matched conditions have coarse matched-stratum comparisons', 'gap' => 'A missing source field is unavailable, not absent. Comparisons are observational, not causal. Preregistered shadow trials provide later-game confirmation; sufficient future outcomes are still required.'],
            ['evidence' => 'Player props and simulations', 'collection' => 'Separate existing experimental snapshots', 'grading' => 'Separate experiment reports', 'gap' => 'Not pooled with game evidence or represented as validated signals.'],
        ];
    }
}
