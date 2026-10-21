<?php

namespace App\Services\CFB\Predictions;

use App\Services\Api\V2\CanonicalSportPredictionQuery;
use Carbon\CarbonImmutable;

/** Read-only export of already frozen evidence; never reconstructs candidates from current metrics. */
class CfbWinnerAuditDataset
{
    public function export(int $season): array
    {
        $rows = $excluded = $seen = [];
        $query = app(CanonicalSportPredictionQuery::class)->queryForSport('cfb', ['season' => $season, 'season_type' => '2'])
            ->setEagerLoads([])->with(['markets', 'sportEvent.cfbGame.homeTeam', 'sportEvent.cfbGame.awayTeam',
                'calculationRun.release', 'calculationRun.inputSnapshot'])
            ->whereIn('cfb_games.status', ['STATUS_FINAL', 'STATUS_FINAL_OVERTIME'])
            ->reorder()->orderBy('predictions.id');
        foreach ($query->lazy(1) as $prediction) {
            $game = $prediction->sportEvent->cfbGame;
            if (isset($seen[$game->id])) {
                throw new \LogicException('Multiple published forecasts for one game; resolve publication identity before auditing.');
            }
            $seen[$game->id] = true;
            if (! app(CfbCandidateReview::class)->safe($prediction)) {
                $excluded['unsafe_pregame_provenance'] = ($excluded['unsafe_pregame_provenance'] ?? 0) + 1;

                continue;
            }
            $snapshot = $prediction->calculationRun->inputSnapshot;
            $inputs = $snapshot->inputs;
            $metadata = $prediction->output_metadata;
            $release = $prediction->calculationRun->release;
            $probability = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')?->probability;
            $line = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home')?->projected_line;
            $models = ['home_only' => ['margin' => 1.0]];
            foreach (['independent' => 'forecast_coordination.independent_home_margin', 'original_primary' => 'forecast_coordination.original_primary.home_margin'] as $name => $path) {
                if (is_numeric($margin = data_get($metadata, $path))) {
                    $models[$name] = ['margin' => (float) $margin];
                }
            }
            foreach (['market', 'result_rating'] as $name) {
                if (is_numeric($candidate = data_get($metadata, 'comparison_forecasts.models.'.$name.'.home_line'))) {
                    $models[$name] = ['margin' => -(float) $candidate];
                }
            }
            $baseline = app(CfbFrozenBaselineComparison::class)->evaluate($inputs,
                CarbonImmutable::parse($snapshot->captured_at), CarbonImmutable::parse($prediction->sportEvent->starts_at), 0);
            foreach ($baseline['margins'] ?? [] as $name => $margin) {
                $models['baseline_'.$name] = ['margin' => $margin];
            }
            $rows[] = ['game_id' => $game->id, 'prediction_id' => $prediction->public_id, 'snapshot_id' => $snapshot->id,
                'release' => $release->semantic_version, 'configuration_hash' => $release->configuration_hash,
                'kickoff' => $prediction->sportEvent->starts_at->toIso8601String(),
                'captured_at' => $snapshot->captured_at->toIso8601String(), 'pregame_safe' => true,
                'home' => $game->homeTeam->abbreviation, 'away' => $game->awayTeam->abbreviation,
                'home_score' => $game->home_score, 'away_score' => $game->away_score,
                'home_probability' => is_numeric($probability) ? (float) $probability : null,
                'home_margin' => is_numeric($line) ? -(float) $line : null, 'models' => $models,
                'quality' => CfbPredictionInputQuality::assess($inputs),
                'home_metrics' => data_get($inputs, 'home.metrics', []), 'away_metrics' => data_get($inputs, 'away.metrics', []),
                'home_metric_season' => data_get($inputs, 'home.metrics.record_season'),
                'away_metric_season' => data_get($inputs, 'away.metrics.record_season'), 'season' => $season,
                'retained_indirect_effects' => data_get($metadata, 'forecast_coordination.retained_indirect_effects', [])];
        }

        return ['schema' => 'cfb-winner-audit-dataset-v1', 'source' => 'configured_database_read_only',
            'queried_at' => now()->toIso8601String(), 'season' => $season, 'excluded' => $excluded, 'rows' => $rows];
    }
}
