<?php

namespace App\Services\NBA;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HistoricalSnapshotQueryService
{
    public function availableSeasons(): Collection
    {
        return DB::connection('nba_snapshot')
            ->table('nba_games')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->map(fn (mixed $season): int => (int) $season)
            ->values();
    }

    public function trainingRowsQuery(?int $season = null): Builder
    {
        $predictionColumns = $this->predictionColumns();

        return $this->baseTrainingQuery($season)
            ->select([
                'p.id as prediction_id',
                'p.game_id',
                'g.season',
                'g.game_date',
                'g.espn_event_id',
                'g.home_team_id',
                'g.away_team_id',
                'ht.abbreviation as home_team_abbreviation',
                'at.abbreviation as away_team_abbreviation',
                'g.home_score',
                'g.away_score',
                'p.home_elo',
                'p.away_elo',
                'p.home_recent_form',
                'p.away_recent_form',
                'p.rest_days_home',
                'p.rest_days_away',
                'p.injury_spread_adj',
                'p.injury_total_adj',
                'p.vegas_spread',
                'p.predicted_spread',
                'p.predicted_total',
                'p.win_probability',
                'p.confidence_score',
                'p.actual_spread',
                'p.actual_total',
                'p.spread_error',
                'p.total_error',
                'p.winner_correct',
            ])
            ->when(
                in_array('model_version', $predictionColumns, true),
                fn (Builder $builder) => $builder->addSelect('p.model_version'),
                fn (Builder $builder) => $builder->selectRaw('NULL as model_version')
            )
            ->when(
                in_array('feature_version', $predictionColumns, true),
                fn (Builder $builder) => $builder->addSelect('p.feature_version'),
                fn (Builder $builder) => $builder->selectRaw('NULL as feature_version')
            )
            ->when(
                in_array('blend_version', $predictionColumns, true),
                fn (Builder $builder) => $builder->addSelect('p.blend_version'),
                fn (Builder $builder) => $builder->selectRaw('NULL as blend_version')
            )
            ->selectRaw('(g.home_score - g.away_score) as derived_actual_spread')
            ->selectRaw('(g.home_score + g.away_score) as derived_actual_total')
            ->orderBy('g.game_date')
            ->orderBy('p.id');
    }

    public function trainingRows(?int $season = null, int $limit = 0): Collection
    {
        $query = $this->trainingRowsQuery($season);

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(function (object $row): array {
            return [
                'prediction_id' => (int) $row->prediction_id,
                'game_id' => (int) $row->game_id,
                'season' => (int) $row->season,
                'game_date' => (string) $row->game_date,
                'espn_event_id' => $row->espn_event_id,
                'home_team_id' => (int) $row->home_team_id,
                'away_team_id' => (int) $row->away_team_id,
                'home_team_abbreviation' => (string) $row->home_team_abbreviation,
                'away_team_abbreviation' => (string) $row->away_team_abbreviation,
                'home_score' => $this->nullableInt($row->home_score),
                'away_score' => $this->nullableInt($row->away_score),
                'home_elo' => $this->nullableFloat($row->home_elo),
                'away_elo' => $this->nullableFloat($row->away_elo),
                'home_recent_form' => $this->nullableFloat($row->home_recent_form),
                'away_recent_form' => $this->nullableFloat($row->away_recent_form),
                'rest_days_home' => $this->nullableInt($row->rest_days_home),
                'rest_days_away' => $this->nullableInt($row->rest_days_away),
                'injury_spread_adj' => $this->nullableFloat($row->injury_spread_adj),
                'injury_total_adj' => $this->nullableFloat($row->injury_total_adj),
                'vegas_spread' => $this->nullableFloat($row->vegas_spread),
                'predicted_spread' => $this->nullableFloat($row->predicted_spread),
                'predicted_total' => $this->nullableFloat($row->predicted_total),
                'win_probability' => $this->nullableFloat($row->win_probability),
                'confidence_score' => $this->nullableFloat($row->confidence_score),
                'model_version' => $row->model_version,
                'feature_version' => $row->feature_version,
                'blend_version' => $row->blend_version,
                'actual_spread' => $this->nullableFloat($row->actual_spread),
                'actual_total' => $this->nullableFloat($row->actual_total),
                'spread_error' => $this->nullableFloat($row->spread_error),
                'total_error' => $this->nullableFloat($row->total_error),
                'winner_correct' => is_null($row->winner_correct) ? null : (bool) $row->winner_correct,
                'derived_actual_spread' => $this->nullableFloat($row->derived_actual_spread),
                'derived_actual_total' => $this->nullableFloat($row->derived_actual_total),
            ];
        });
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function datasetSummary(?int $season = null): array
    {
        $row = $this->baseTrainingQuery($season)
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COUNT(CASE WHEN p.actual_spread IS NOT NULL THEN 1 END) as graded_prediction_count')
            ->selectRaw('MIN(g.game_date) as first_game_date')
            ->selectRaw('MAX(g.game_date) as last_game_date')
            ->selectRaw('AVG(ABS(COALESCE(p.spread_error, ((g.home_score - g.away_score) - p.predicted_spread)))) as avg_spread_error')
            ->first();

        return [
            'season' => $season,
            'row_count' => (int) ($row?->row_count ?? 0),
            'graded_prediction_count' => (int) ($row?->graded_prediction_count ?? 0),
            'first_game_date' => $row?->first_game_date,
            'last_game_date' => $row?->last_game_date,
            'avg_spread_error' => $this->nullableFloat($row?->avg_spread_error),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<int, string>
     */
    private function predictionColumns(): array
    {
        return Schema::connection('nba_snapshot')->getColumnListing('nba_predictions');
    }

    private function baseTrainingQuery(?int $season = null): Builder
    {
        $query = DB::connection('nba_snapshot')
            ->table('nba_predictions as p')
            ->join('nba_games as g', 'p.game_id', '=', 'g.id')
            ->join('nba_teams as ht', 'g.home_team_id', '=', 'ht.id')
            ->join('nba_teams as at', 'g.away_team_id', '=', 'at.id')
            ->where('g.status', 'STATUS_FINAL');

        if ($season !== null) {
            $query->where('g.season', $season);
        }

        return $query;
    }
}
