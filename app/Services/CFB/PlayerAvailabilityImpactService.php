<?php

namespace App\Services\CFB;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlayerAvailabilityImpactService
{
    /**
     * @return array{
     *   available:bool,
     *   team_id:int,
     *   out:float,
     *   questionable:float,
     *   raw_out:int,
     *   raw_questionable:int,
     *   players:list<array<string, mixed>>
     * }
     */
    public function impactForTeam(int $teamId, ?int $season = null, ?string $asOfDate = null): array
    {
        $empty = [
            'available' => Schema::hasTable('cfb_player_injuries'),
            'team_id' => $teamId,
            'out' => 0.0,
            'questionable' => 0.0,
            'raw_out' => 0,
            'raw_questionable' => 0,
            'players' => [],
        ];

        if ($teamId <= 0 || ! $empty['available']) {
            return $empty;
        }

        $query = DB::table('cfb_player_injuries as i')
            ->leftJoin('cfb_players as p', 'p.id', '=', 'i.player_id')
            ->where('i.team_id', $teamId)
            ->where('i.is_active', true);

        if ($asOfDate !== null && $asOfDate !== '') {
            $query
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('i.injury_date')
                        ->orWhereDate('i.injury_date', '<=', $asOfDate);
                })
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('i.return_date')
                        ->orWhereDate('i.return_date', '>=', $asOfDate);
                })
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('i.source_updated_at')
                        ->orWhereDate('i.source_updated_at', '<=', $asOfDate);
                });
        }

        $rows = $query->get([
            'i.player_id',
            'i.status',
            'i.detail',
            'i.type',
            'p.full_name',
            'p.position',
        ]);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $impact = $empty;
        $players = [];

        foreach ($rows as $row) {
            $bucket = $this->statusBucket((string) ($row->status ?? ''));
            if ($bucket === null) {
                continue;
            }

            $playerId = (int) ($row->player_id ?? 0);
            $position = strtoupper(trim((string) ($row->position ?? '')));
            $availabilityWeight = $this->availabilityWeight((string) ($row->status ?? ''));
            $positionWeight = $this->positionWeight($position);
            $productionMultiplier = $this->productionMultiplier($playerId, $position, $season, $asOfDate);
            $weightedImpact = round($availabilityWeight * $positionWeight * $productionMultiplier, 3);

            $impact[$bucket] = round((float) $impact[$bucket] + $weightedImpact, 3);
            $impact['raw_'.$bucket]++;
            $players[] = [
                'player_id' => $playerId,
                'player_name' => $row->full_name,
                'position' => $position ?: null,
                'status' => $row->status,
                'detail' => $row->detail,
                'bucket' => $bucket,
                'availability_weight' => round($availabilityWeight, 3),
                'position_weight' => round($positionWeight, 3),
                'production_multiplier' => round($productionMultiplier, 3),
                'weighted_impact' => $weightedImpact,
            ];
        }

        usort(
            $players,
            fn (array $a, array $b): int => ($b['weighted_impact'] <=> $a['weighted_impact'])
        );

        $impact['out'] = round((float) $impact['out'], 3);
        $impact['questionable'] = round((float) $impact['questionable'], 3);
        $impact['players'] = array_slice($players, 0, 12);

        return $impact;
    }

    public function adjustedTeamRating(int $teamId, float $baseRating, ?int $season = null, ?string $asOfDate = null): ?float
    {
        $impact = $this->impactForTeam($teamId, $season, $asOfDate);
        if (! $impact['available']) {
            return null;
        }

        $adjustment = $this->ratingPenalty($impact);

        return round($baseRating - $adjustment, 3);
    }

    public function totalAdjustment(int $teamId, ?int $season = null, ?string $asOfDate = null): ?float
    {
        $impact = $this->impactForTeam($teamId, $season, $asOfDate);
        if (! $impact['available']) {
            return null;
        }

        $outPenalty = (float) config('cfb.predictions.injury_out_total_penalty', 0.30);
        $questionablePenalty = (float) config('cfb.predictions.injury_questionable_total_penalty', 0.10);

        return round(-(((float) $impact['out'] * $outPenalty) + ((float) $impact['questionable'] * $questionablePenalty)), 3);
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    public function ratingPenalty(array $impact): float
    {
        $outPenalty = (float) config('cfb.metrics.injury_out_rating_penalty', 18.0);
        $questionablePenalty = (float) config('cfb.metrics.injury_questionable_rating_penalty', 7.0);

        return round((((float) ($impact['out'] ?? 0.0)) * $outPenalty) + (((float) ($impact['questionable'] ?? 0.0)) * $questionablePenalty), 3);
    }

    protected function statusBucket(string $status): ?string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'out')
            || str_contains($normalized, 'doubtful')
            || str_contains($normalized, 'inactive')
            || str_contains($normalized, 'suspended')
            || str_contains($normalized, 'ir')
        ) {
            return 'out';
        }

        if (
            str_contains($normalized, 'questionable')
            || str_contains($normalized, 'game-time')
            || str_contains($normalized, 'gtd')
            || str_contains($normalized, 'probable')
            || str_contains($normalized, 'day-to-day')
        ) {
            return 'questionable';
        }

        return null;
    }

    protected function availabilityWeight(string $status): float
    {
        $normalized = strtolower(trim($status));

        $weight = match (true) {
            str_contains($normalized, 'probable') => (float) config('cfb.predictions.player_availability.availability_weights.probable', 0.25),
            str_contains($normalized, 'questionable'),
            str_contains($normalized, 'game-time'),
            str_contains($normalized, 'gtd'),
            str_contains($normalized, 'day-to-day') => (float) config('cfb.predictions.player_availability.availability_weights.questionable', 0.55),
            str_contains($normalized, 'doubtful') => (float) config('cfb.predictions.player_availability.availability_weights.doubtful', 0.85),
            default => (float) config('cfb.predictions.player_availability.availability_weights.out', 1.0),
        };

        return max(0.0, min(1.0, $weight));
    }

    protected function positionWeight(string $position): float
    {
        $normalized = $this->positionGroup($position);

        return (float) config("cfb.predictions.player_availability.position_weights.{$normalized}", 0.85);
    }

    protected function positionGroup(string $position): string
    {
        return match (strtoupper(trim($position))) {
            'QB' => 'QB',
            'RB', 'FB' => 'RB',
            'WR' => 'WR',
            'TE' => 'TE',
            'C', 'G', 'OG', 'T', 'OT', 'OL' => 'OL',
            'DT', 'DE', 'DL', 'EDGE' => 'DL',
            'LB', 'ILB', 'OLB' => 'LB',
            'CB', 'S', 'FS', 'SS', 'DB' => 'DB',
            'K', 'PK' => 'K',
            'P' => 'P',
            default => 'OTHER',
        };
    }

    protected function productionMultiplier(int $playerId, string $position, ?int $season, ?string $asOfDate): float
    {
        if ($playerId <= 0 || ! Schema::hasTable('cfb_player_stats')) {
            return $this->unknownProductionMultiplier($position);
        }

        $lookback = max(3, min(20, (int) config('cfb.predictions.player_availability.lookback_games', 8)));
        $query = DB::table('cfb_player_stats as ps')
            ->join('cfb_games as g', 'g.id', '=', 'ps.game_id')
            ->where('ps.player_id', $playerId);

        if ($season !== null) {
            $query->whereBetween('g.season', [$season - 2, $season]);
        }

        if ($asOfDate !== null && $asOfDate !== '') {
            $query->whereDate('g.game_date', '<', $asOfDate);
        }

        /** @var Collection<int, object> $rows */
        $rows = $query
            ->orderByDesc('g.game_date')
            ->orderByDesc('ps.game_id')
            ->limit($lookback)
            ->get([
                'ps.passing_attempts',
                'ps.passing_yards',
                'ps.passing_touchdowns',
                'ps.rushing_attempts',
                'ps.rushing_yards',
                'ps.rushing_touchdowns',
                'ps.receptions',
                'ps.receiving_targets',
                'ps.receiving_yards',
                'ps.receiving_touchdowns',
                'ps.tackles_total',
                'ps.sacks',
                'ps.interceptions',
                'ps.passes_defended',
                'ps.fumbles_forced',
                'ps.fumbles_recovered',
                'ps.field_goals_made',
                'ps.field_goals_attempted',
                'ps.extra_points_attempted',
            ]);

        if ($rows->isEmpty()) {
            return $this->unknownProductionMultiplier($position);
        }

        $group = $this->positionGroup($position);
        $sum = 0.0;

        foreach ($rows as $row) {
            $sum += $this->productionScore($row, $group);
        }

        $average = $sum / max(1, $rows->count());
        $baseline = max(1.0, (float) config("cfb.predictions.player_availability.production_baselines.{$group}", 6.0));
        $multiplier = $average / $baseline;

        if (! is_finite($multiplier) || $multiplier <= 0) {
            return $this->unknownProductionMultiplier($position);
        }

        $min = (float) config('cfb.predictions.player_availability.min_player_multiplier', 0.35);
        $max = (float) config('cfb.predictions.player_availability.max_player_multiplier', 3.0);

        return round(max($min, min($max, $multiplier)), 3);
    }

    protected function unknownProductionMultiplier(string $position): float
    {
        $group = $this->positionGroup($position);
        $configured = config("cfb.predictions.player_availability.unknown_production_multipliers.{$group}");

        if (is_numeric($configured)) {
            return round(max(0.1, min(2.0, (float) $configured)), 3);
        }

        return round(max(0.1, min(2.0, (float) config('cfb.predictions.player_availability.unknown_player_multiplier', 0.75))), 3);
    }

    protected function productionScore(object $row, string $group): float
    {
        return match ($group) {
            'QB' => ((float) ($row->passing_attempts ?? 0) * 0.35)
                + ((float) ($row->passing_yards ?? 0) / 25.0)
                + ((float) ($row->passing_touchdowns ?? 0) * 4.0)
                + ((float) ($row->rushing_attempts ?? 0) * 0.15)
                + ((float) ($row->rushing_yards ?? 0) / 35.0)
                + ((float) ($row->rushing_touchdowns ?? 0) * 3.0),
            'RB' => ((float) ($row->rushing_attempts ?? 0) * 0.35)
                + ((float) ($row->rushing_yards ?? 0) / 20.0)
                + ((float) ($row->rushing_touchdowns ?? 0) * 3.0)
                + ((float) ($row->receptions ?? 0) * 0.45)
                + ((float) ($row->receiving_yards ?? 0) / 30.0),
            'WR', 'TE' => ((float) ($row->receptions ?? 0) * 0.90)
                + ((float) ($row->receiving_targets ?? 0) * 0.20)
                + ((float) ($row->receiving_yards ?? 0) / 18.0)
                + ((float) ($row->receiving_touchdowns ?? 0) * 3.0),
            'DL', 'LB', 'DB' => ((float) ($row->tackles_total ?? 0) * 0.35)
                + ((float) ($row->sacks ?? 0) * 3.0)
                + ((float) ($row->interceptions ?? 0) * 3.0)
                + ((float) ($row->passes_defended ?? 0) * 0.80)
                + ((float) ($row->fumbles_forced ?? 0) * 1.20)
                + ((float) ($row->fumbles_recovered ?? 0) * 1.20),
            'K' => ((float) ($row->field_goals_attempted ?? 0) * 0.90)
                + ((float) ($row->field_goals_made ?? 0) * 0.50)
                + ((float) ($row->extra_points_attempted ?? 0) * 0.25),
            default => ((float) ($row->passing_yards ?? 0) / 35.0)
                + ((float) ($row->rushing_yards ?? 0) / 25.0)
                + ((float) ($row->receiving_yards ?? 0) / 25.0)
                + ((float) ($row->tackles_total ?? 0) * 0.25),
        };
    }
}
