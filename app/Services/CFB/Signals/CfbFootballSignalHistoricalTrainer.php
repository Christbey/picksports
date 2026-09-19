<?php

namespace App\Services\CFB\Signals;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CFB\Game;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamStat;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbHistoricalSignalEvidenceBuilder;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Retrospective training from prior games, explicitly separate from archived live forecasts. */
class CfbFootballSignalHistoricalTrainer
{
    public static function key(array $configuration): string
    {
        return 'cfb:signal-history:v1:'.app(CanonicalPayloadHasher::class)->hash($configuration);
    }

    public function train(array $configuration, int $fromSeason, int $toSeason, CarbonImmutable $asOf, ?callable $progress = null): array
    {
        $games = Game::whereBetween('season', [$fromSeason - 1, $toSeason])->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '<', $asOf->toDateString())->where('updated_at', '<=', $asOf)
            ->whereNotNull('home_score')->whereNotNull('away_score')->whereColumn('home_score', '!=', 'away_score')
            ->where('home_score', '>=', 0)->where('away_score', '>=', 0)
            ->select(['id', 'sport_event_id', 'season', 'week', 'game_date', 'game_time', 'home_team_id', 'away_team_id',
                'home_score', 'away_score', 'neutral_site', 'conference_game', 'created_at', 'updated_at'])
            ->with('sportEvent:id,starts_at')->orderByDesc('game_date')->orderByDesc('id')->get();
        $stats = TeamStat::whereIn('game_id', $games->modelKeys())->where('updated_at', '<=', $asOf)
            ->select(['id', 'game_id', 'team_id', 'updated_at', 'passing_yards', 'rushing_yards', 'total_yards', 'sacks_allowed',
                'penalty_yards', 'first_downs', 'passing_attempts', 'rushing_attempts', 'passing_completions',
                'third_down_conversions', 'third_down_attempts', 'fourth_down_conversions', 'fourth_down_attempts',
                'red_zone_scores', 'red_zone_attempts', 'interceptions', 'fumbles_lost'])
            ->get()->keyBy(fn ($s) => $s->game_id.':'.$s->team_id);
        $prior = TeamMetric::whereBetween('season', [$fromSeason - 1, $toSeason - 1])->where('updated_at', '<=', $asOf)
            ->select(['team_id', 'season', 'wins', 'losses', 'fpi', 'points_per_game', 'points_allowed_per_game'])
            ->get()->keyBy(fn ($m) => $m->season.':'.$m->team_id);
        $byTeam = [];
        foreach ($games as $game) {
            $byTeam[$game->home_team_id][] = $game;
            $byTeam[$game->away_team_id][] = $game;
        }
        $base = $configuration;
        $base['football_signals']['enabled'] = false;
        $release = new CalculationReleaseData('historical-research', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules',
            'historical-research', 'prior-game-reconstruction', CfbFootballSignalEvidence::baselineHash($configuration), 'cfb-pregame-v1', $base);
        $observations = $sourceIds = [];
        $catalog = $configuration['football_signals']['catalog'];
        $builder = app(CfbHistoricalSignalEvidenceBuilder::class);
        foreach ($games as $target) {
            if ($target->season < $fromSeason || ! $target->sportEvent?->starts_at) {
                continue;
            }
            $homePrior = $prior->get(($target->season - 1).':'.$target->home_team_id);
            $awayPrior = $prior->get(($target->season - 1).':'.$target->away_team_id);
            // Use prior-season ratings only. Current-season final ratings would leak future games.
            if (! is_numeric($homePrior?->fpi) || ! is_numeric($awayPrior?->fpi)) {
                continue;
            }
            $cutoff = $target->sportEvent->starts_at->toImmutable();
            $related = collect([...($byTeam[$target->home_team_id] ?? []), ...($byTeam[$target->away_team_id] ?? [])])
                ->unique('id')->sortByDesc(fn ($g) => $g->game_date->toDateString().sprintf('%09d', $g->id));
            $history = $builder->reconstruct($target, $asOf, $cutoff, $related, $stats);
            $inputs = ['event' => ['season' => (int) $target->season, 'week' => (int) $target->week,
                'starts_at' => $cutoff->toIso8601String(), 'neutral_site' => (bool) $target->neutral_site],
                'historical_signals' => $history, 'signal_context' => ['conference' => (bool) $target->conference_game]];
            foreach (['home' => $homePrior, 'away' => $awayPrior] as $side => $priorMetric) {
                $window = $history[$side]['windows']['current_season'];
                $n = $window['games'];
                $get = fn ($metric) => data_get($window, 'metrics.'.$metric.'.value');
                $wins = (int) round(($get('win_rate') ?? 0) * $n);
                $inputs[$side] = ['injuries' => [], 'elo' => null,
                    'metrics' => ['record_season' => (int) $target->season, 'wins' => $wins, 'losses' => $n - $wins,
                        'fpi' => (float) $priorMetric->fpi, 'points_per_game' => $get('points_per_game'),
                        'points_allowed_per_game' => $get('points_allowed_per_game'),
                        'turnover_differential' => $get('turnover_margin_per_game')],
                    'prior_metrics' => ['record_season' => (int) $target->season - 1, 'wins' => $priorMetric->wins,
                        'losses' => $priorMetric->losses, 'points_per_game' => $priorMetric->points_per_game,
                        'points_allowed_per_game' => $priorMetric->points_allowed_per_game]];
            }
            $baselineInputs = $inputs;
            // Paired FPI overrides the spread baseline; satisfy the parent calculator's numeric
            // Elo contract without presenting synthetic Elo as an observed signal feature.
            $baselineInputs['home']['elo'] = $baselineInputs['away']['elo'] = 1500;
            $output = app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $baselineInputs, $cutoff->subSecond()), $release);
            $residuals = ['spread' => $target->home_score - $target->away_score - $output->metadata['home_margin'],
                'total' => $target->home_score + $target->away_score - $output->diagnostics['projected_total']];
            foreach (['home', 'away'] as $side) {
                $features = CfbFootballSignalCatalog::features($inputs, $side, $configuration['football_signals']['feature_policy']);
                foreach ($catalog as $id => $rule) {
                    if (CfbFootballSignalCatalog::evaluate($rule, $features) !== true) {
                        continue;
                    }
                    $value = $residuals[$rule['market']] * ($rule['market'] === 'spread' && $side === 'away' ? -1 : 1);
                    $existing = $observations[$id][$target->id]['residual'] ?? null;
                    $observations[$id][$target->id] = ['game_id' => $target->id, 'starts_at' => $cutoff->toIso8601String(),
                        'residual' => $existing === null ? $value : ($existing + $value) / 2];
                }
            }
            $sourceIds[] = $target->id;
            if (count($sourceIds) % 100 === 0 && $progress) {
                $progress(count($sourceIds));
            }
        }
        $artifact = ['schema' => 'cfb-signal-history-v1', 'available_at' => CarbonImmutable::now()->toIso8601String(), 'as_of' => $asOf->toIso8601String(),
            'baseline_hash' => CfbFootballSignalEvidence::baselineHash($configuration), 'from_season' => $fromSeason, 'to_season' => $toSeason,
            'historical_availability_proven' => false, 'baseline_rating_source' => 'prior_season_fpi',
            'limitations' => ['retrospective_revised_box_scores', 'prior_season_fpi_differs_from_weekly_fpi',
                'unarchived_weather_personnel_and_market_conditions_remain_unknown', 'not_live_profitability_validation'],
            'source_game_ids' => $sourceIds, 'observations' => $observations];
        Cache::put(self::key($configuration), $artifact, now()->addDays(8));

        return $artifact;
    }
}
