<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Collection;

/** Read-only, controlled baseline comparison. This does not replay the full recommendation model. */
class NflPropProjectionBacktest extends PlayerPropAnalyzer
{
    public function run(int $season, string $throughDate, int $minGames = 3, ?string $validationFrom = null, ?string $market = null, bool $includeSamples = false): array
    {
        $gameFields = ['id', 'season', 'season_type', 'status', 'game_date', 'game_time', 'home_team_id', 'away_team_id', 'home_qb_name', 'away_qb_name'];
        $props = PlayerProp::query()->whereNotNull('player_id')
            ->when($market !== null, fn ($query) => $query->where('market', $market))
            ->whereHas('game', fn ($q) => $q->where('season', $season)->whereIn('season_type', ['2', 'regular'])
                ->where('status', 'STATUS_FINAL')->whereDate('game_date', '<=', $throughDate))
            ->with(['game' => fn ($q) => $q->select($gameFields), 'player:id,full_name,position'])
            ->get(['id', 'game_id', 'player_id', 'market', 'bookmaker', 'line', 'over_price', 'under_price', 'fetched_at']);
        $stats = $props->isEmpty() ? collect() : PlayerStat::query()
            ->whereIn('player_id', $props->pluck('player_id')->unique())
            ->whereHas('game', fn ($q) => $q->whereBetween('season', [$season - 1, $season])
                ->whereIn('season_type', ['2', 'regular'])->where('status', 'STATUS_FINAL')->whereDate('game_date', '<=', $throughDate))
            ->with(['game' => fn ($q) => $q->select($gameFields)])
            ->get(['id', 'game_id', 'player_id', 'team_id', 'passing_attempts', 'passing_yards', 'passing_completions', 'passing_touchdowns', 'interceptions_thrown',
                'rushing_attempts', 'rushing_yards', 'rushing_touchdowns', 'receiving_targets', 'receptions', 'receiving_yards', 'receiving_touchdowns']);

        $result = $this->evaluate($props, $stats, $minGames, $includeSamples);
        if ($validationFrom !== null) {
            [$development, $validation] = $props->partition(fn ($prop) => $prop->game->game_date->toDateString() < $validationFrom);
            $result['date_split'] = [
                'validation_from' => $validationFrom,
                'development' => $validation->isEmpty() ? $result : $this->evaluate($development, $stats, $minGames),
                'validation' => $development->isEmpty() ? $result : $this->evaluate($validation, $stats, $minGames),
                'policy' => 'Fixed formulas; each target uses only earlier game dates. Previously inspected dates are retrospective evidence, not an untouched holdout.',
            ];
        }

        return $result;
    }

    /** Public pure evaluator also permits fixtures and bounded production aggregate checks without writes. */
    public function evaluate(Collection $props, Collection $stats, int $minGames = 3, bool $includeSamples = false): array
    {
        $dates = app(SportsDateWindowService::class);
        $stats = $stats->filter(fn ($row) => $row->game?->status === 'STATUS_FINAL'
            && in_array((string) $row->game->season_type, ['2', 'regular'], true));
        $byPlayer = $stats->groupBy('player_id')->map(fn ($rows) => $rows->sortByDesc(fn ($r) => $dates->gameDateTimeUtc($r->game->game_date, $r->game->game_time)?->timestamp ?? 0)->values());
        $coverage = ['quotes' => $props->count(), 'distinct_player_game_markets' => 0, 'evaluated' => 0, 'missing_actual' => 0,
            'insufficient_history' => 0, 'nonstarter_targets' => 0, 'missing_identity_or_kickoff' => 0, 'unusable_quotes' => 0];
        $markets = [];
        $samples = [];
        foreach ($props->groupBy(fn ($p) => $p->game_id.':'.$p->player_id.':'.$p->market) as $quotes) {
            $coverage['distinct_player_game_markets']++;
            $prop = $quotes->first();
            $target = $prop->game;
            $kickoff = $target ? $dates->gameDateTimeUtc($target->game_date, $target->game_time) : null;
            if (! $prop->player || ! $kickoff || blank($target->game_time)) {
                $coverage['missing_identity_or_kickoff']++;

                continue;
            }
            $field = $this->getStatFieldForMarket($prop->market);
            $playerStats = $byPlayer->get($prop->player_id, collect());
            $actualRow = $playerStats->firstWhere('game_id', $target->id);
            $actual = $actualRow && $field ? $this->statValue($actualRow, $field) : null;
            if ($actual === null || ! in_array((int) $actualRow->team_id, [(int) $target->home_team_id, (int) $target->away_team_id], true)) {
                $coverage['missing_actual']++;

                continue;
            }
            $teamId = (int) $actualRow->team_id;
            $isHome = $teamId === (int) $target->home_team_id;
            $targetQb = $isHome ? $target->home_qb_name : $target->away_qb_name;
            if ($prop->player->position === 'QB' && filled($targetQb) && strcasecmp(trim($targetQb), trim($prop->player->full_name)) !== 0) {
                $coverage['nonstarter_targets']++;

                continue;
            }
            $prior = $playerStats->filter(function ($row) use ($target, $teamId, $field) {
                // Match the deployed baseline's date boundary; no target-day or future outcomes.
                return (int) $row->team_id === $teamId && (int) $row->game->season >= (int) $target->season - 1
                    && (int) $row->game->season <= (int) $target->season
                    && $row->game->game_date->toDateString() < $target->game_date->toDateString()
                    && $this->statValue($row, $field) !== null;
            })->values();
            $current = $prior->filter(fn ($r) => (int) $r->game->season === (int) $target->season)->values();
            $history = $current->isNotEmpty() ? $current : $prior;
            $newPrior = app(NflPropRoleHistory::class)->select($prior, $prop->player)['history'];
            $newCurrent = $newPrior->filter(fn ($r) => (int) $r->game->season === (int) $target->season)->values();
            $newHistory = $newCurrent->isNotEmpty() ? $newCurrent : $newPrior;
            if ($prior->count() < $minGames || $newPrior->count() < $minGames || $history->isEmpty() || $newHistory->isEmpty()) {
                $coverage['insufficient_history']++;

                continue;
            }
            $opponentId = $isHome ? (int) $target->away_team_id : (int) $target->home_team_id;
            $unfilteredOld = $this->legacyBaseline($history, $prop->market, $field, $teamId, $opponentId, $isHome);
            $old = $this->legacyBaseline($newHistory, $prop->market, $field, $teamId, $opponentId, $isHome);
            $opportunity = app(NflPropOpportunityProjection::class)->project($newHistory, $prop->market);
            $new = $opportunity && $opportunity['missing_opportunity_games'] === 0 ? $opportunity['baseline_mean']
                : $this->recencyWeightedAverage($this->statValues($newHistory->take(16), $field), 0.86);
            $stabilized = app(NflPropOpportunityProjection::class)->projectStabilized($newHistory, $prop->market);
            $stableMean = $stabilized && $stabilized['missing_opportunity_games'] === 0 ? $stabilized['baseline_mean']
                : $this->recencyWeightedAverage($this->statValues($newHistory->take(16), $field), 0.86);
            if (! is_numeric($old) || ! is_numeric($new)) {
                $coverage['insufficient_history']++;

                continue;
            }
            $coverage['evaluated']++;
            $usable = $quotes->filter(function ($quote) use ($kickoff) {
                $at = $quote->fetched_at;

                return $at && is_numeric($quote->line) && $at->lte($kickoff) && $at->gte($kickoff->subHours(36));
            })->sortBy(fn ($quote) => [$quote->bookmaker === 'draftkings' ? 0 : 1, -($quote->fetched_at?->timestamp ?? 0), $quote->id])->first();
            $coverage['unusable_quotes'] += (int) ($usable === null);
            if ($includeSamples) {
                $samples[] = [
                    'game_id' => (int) $target->id,
                    'player_id' => (int) $prop->player_id,
                    'market' => $prop->market,
                    'game_start_at' => $kickoff->toIso8601String(),
                    'history_max_game_start_at' => $newHistory->map(fn ($row) => $dates->gameDateTimeUtc($row->game->game_date, $row->game->game_time))->filter()->max()?->toIso8601String(),
                    'actual' => $actual,
                    'legacy_mean' => $old,
                    'recent5_mean' => $this->averageStatValue($newHistory->take(5), $field),
                    'quote_line' => $usable ? (float) $usable->line : null,
                    'quote_observed_at' => $usable?->fetched_at?->toIso8601String(),
                    'over_price' => $usable?->over_price,
                    'under_price' => $usable?->under_price,
                    ...$this->benchmarkFeatures($newHistory, $field, $teamId, $opponentId, $isHome, $target->season),
                ];
            }
            $markets[$prop->market] ??= ['n' => 0, 'old_abs' => 0.0, 'new_abs' => 0.0, 'old_bias' => 0.0, 'new_bias' => 0.0,
                'stabilized_abs' => 0.0, 'stabilized_bias' => 0.0, 'stabilized_bets' => $this->emptyBets(),
                'unfiltered_old_abs' => 0.0, 'role_history_changed' => 0,
                'complete_opportunity_samples' => 0, 'stat_fallback_samples' => 0,
                'new_better' => 0, 'old_better' => 0, 'ties' => 0, 'old_bets' => $this->emptyBets(), 'new_bets' => $this->emptyBets()];
            $bucket = &$markets[$prop->market];
            $bucket['n']++;
            $bucket['unfiltered_old_abs'] += abs($unfilteredOld - $actual);
            $bucket['role_history_changed'] += (int) ($history->pluck('id')->all() !== $newHistory->pluck('id')->all());
            $bucket[$opportunity && $opportunity['missing_opportunity_games'] === 0 ? 'complete_opportunity_samples' : 'stat_fallback_samples']++;
            $oldError = abs($old - $actual);
            $newError = abs($new - $actual);
            $bucket['old_abs'] += $oldError;
            $bucket['new_abs'] += $newError;
            $bucket['old_bias'] += $old - $actual;
            $bucket['new_bias'] += $new - $actual;
            $bucket['stabilized_abs'] += abs($stableMean - $actual);
            $bucket['stabilized_bias'] += $stableMean - $actual;
            $bucket[$newError < $oldError - 0.000001 ? 'new_better' : ($oldError < $newError - 0.000001 ? 'old_better' : 'ties')]++;
            if ($usable) {
                $this->settle($bucket['old_bets'], $old, $actual, $usable);
                $this->settle($bucket['new_bets'], $new, $actual, $usable);
                $this->settle($bucket['stabilized_bets'], $stableMean, $actual, $usable);
            }
            unset($bucket);
        }
        foreach ($markets as &$bucket) {
            foreach (['old', 'new', 'stabilized'] as $model) {
                $bucket[$model.'_mae'] = round($bucket[$model.'_abs'] / $bucket['n'], 4);
                $bucket[$model.'_bias'] = round($bucket[$model.'_bias'] / $bucket['n'], 4);
                unset($bucket[$model.'_abs']);
                $bets = &$bucket[$model.'_bets'];
                $bets['units'] = round($bets['units'], 4);
                $bets['roi'] = $bets['bets'] ? round($bets['units'] / $bets['bets'], 4) : null;
                unset($bets);
            }
            $bucket['stabilized_mae_improvement'] = round($bucket['old_mae'] - $bucket['stabilized_mae'], 4);
            $bucket['unfiltered_old_mae'] = round($bucket['unfiltered_old_abs'] / $bucket['n'], 4);
            unset($bucket['unfiltered_old_abs']);
            $bucket['mae_improvement'] = round($bucket['old_mae'] - $bucket['new_mae'], 4);
        }
        unset($bucket);

        $result = ['comparison' => 'controlled_projection_baselines_only', 'history_comparison' => 'same_role_eligible_history_for_both_models', 'coverage' => $coverage, 'markets' => $markets,
            'limitations' => ['Event-date cutoff uses stored stats as they exist now; original source revisions are unavailable.',
                'Neutral context for both models: no reconstructed injuries, defense, pace or depth-chart adjustments.',
                'QB targets are known starters or unknown; both baselines exclude known relief appearances. The unfiltered legacy diagnostic retains the earlier comparison history.',
                'One projection per player/game/market; universe restricted to stored prop markets.',
                'Returns use mean-vs-line direction with one flat unit, not the production recommendation thresholds.',
                'Quotes use saved fetched_at, within 36 hours before kickoff; provider observation history is unavailable.',
                'The stabilized opportunity candidate fixes volume prior strength at three effective games; it is offline and unvalidated.',
                'A chronological date split alone does not establish an untouched holdout.',
                'No calibration or deployment approval is inferred.']];
        if ($includeSamples) {
            $result['sample_schema_version'] = 'nfl-prop-benchmark-v1';
            $result['samples'] = $samples;
        }

        return $result;
    }

    protected function benchmarkFeatures(Collection $history, string $field, int $teamId, int $opponentId, bool $isHome, int $season): array
    {
        $opponent = $history->filter(fn ($row) => in_array($opponentId, [(int) $row->game->home_team_id, (int) $row->game->away_team_id], true));
        $venue = $history->filter(fn ($row) => (int) $row->game->{$isHome ? 'home_team_id' : 'away_team_id'} === $teamId);
        $values = $this->statValues($history->take(16), $field);
        $mean = array_sum($values) / count($values);
        $features = [
            'feature_history_games' => $history->count(),
            'feature_current_season_games' => $history->filter(fn ($row) => (int) $row->game->season === $season)->count(),
            'feature_is_home' => (int) $isHome,
            'feature_season_mean' => $this->averageStatValue($history, $field),
            'feature_recent5_mean' => $this->averageStatValue($history->take(5), $field),
            'feature_recent10_mean' => $this->averageStatValue($history->take(10), $field),
            'feature_recent_weighted_mean' => $this->recencyWeightedAverage($values, 0.86),
            'feature_std_dev' => sqrt(array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $values)) / count($values)),
            'feature_opponent_games' => $opponent->count(),
            'feature_opponent_mean' => $this->averageStatValue($opponent->take(10), $field),
            'feature_venue_games' => $venue->count(),
            'feature_venue_mean' => $this->averageStatValue($venue->take(20), $field),
        ];
        foreach (['passing_attempts', 'rushing_attempts', 'receiving_targets'] as $volume) {
            $volumeRows = $history->take(5)->filter(fn ($row) => is_numeric($row->{$volume}));
            $features['feature_recent_'.$volume] = $volumeRows->isEmpty() ? null : (float) $volumeRows->avg($volume);
        }

        return $features;
    }

    protected function legacyBaseline(Collection $history, string $market, string $field, int $teamId, int $opponentId, bool $isHome): float
    {
        $season = $this->averageStatValue($history, $field);
        $opponent = $history->filter(fn ($r) => in_array($opponentId, [(int) $r->game->home_team_id, (int) $r->game->away_team_id], true))->take(10)->values();
        $venue = $history->filter(fn ($r) => $isHome ? (int) $r->game->home_team_id === $teamId : (int) $r->game->away_team_id === $teamId)->take(20)->values();

        return $this->buildProjection($season,
            $this->recencyWeightedAverage($this->statValues($history->take(10), $field), 0.86),
            $this->recencyWeightedAverage($this->statValues($history->take(5), $field), 0.86),
            $opponent->isNotEmpty() ? $this->recencyWeightedAverage($this->statValues($opponent, $field), 0.82) : null,
            $venue->count() >= 3 ? $this->recencyWeightedAverage($this->statValues($venue, $field), 0.90) : null,
            $opponent->count(), $venue->count(), $this->marketProfiles[$market]);
    }

    private function emptyBets(): array
    {
        return ['bets' => 0, 'wins' => 0, 'losses' => 0, 'pushes' => 0, 'units' => 0.0];
    }

    private function settle(array &$result, float $mean, float $actual, PlayerProp $quote): void
    {
        $line = (float) $quote->line;
        if (abs($mean - $line) < 0.000001) {
            return;
        }
        $over = $mean > $line;
        $price = $over ? $quote->over_price : $quote->under_price;
        if (! is_numeric($price) || abs($price) < 100) {
            return;
        }
        $result['bets']++;
        if ($actual === $line) {
            $result['pushes']++;
        } elseif (($actual > $line) === $over) {
            $result['wins']++;
            $result['units'] += $price > 0 ? $price / 100 : 100 / abs($price);
        } else {
            $result['losses']++;
            $result['units']--;
        }
    }
}
