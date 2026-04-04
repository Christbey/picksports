<?php

namespace App\Services\MLB;

use App\Models\MLB\BullpenRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BullpenRatingService
{
    public function persistForTeam(Team $team, int $season, int|string $seasonType, CarbonInterface|string $asOfDate): ?BullpenRating
    {
        $snapshot = $this->calculateSnapshot($team->id, $season, $seasonType, $asOfDate);

        if ($snapshot === null) {
            return null;
        }

        return BullpenRating::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
                'season_type' => (string) $seasonType,
                'as_of_date' => $snapshot['as_of_date'],
            ],
            $snapshot
        );
    }

    public function updateRanks(int $season, int|string $seasonType, CarbonInterface|string $asOfDate): void
    {
        $date = Carbon::parse($asOfDate)->toDateString();

        $ratings = BullpenRating::query()
            ->where('season', $season)
            ->where('season_type', (string) $seasonType)
            ->whereDate('as_of_date', $date)
            ->orderByDesc('rating_score')
            ->orderBy('team_id')
            ->get();

        foreach ($ratings->values() as $index => $rating) {
            $rating->forceFill(['rating_rank' => $index + 1])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function contextForGame(Game $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! (bool) config('mlb.prediction.situational.bullpen_quality.enabled', true)) {
            return [
                'home_rating' => 0.0,
                'away_rating' => 0.0,
                'home_rank' => null,
                'away_rank' => null,
                'home_source' => null,
                'away_source' => null,
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
            ];
        }

        $home = $this->resolveForGame($game, $homeTeamId);
        $away = $this->resolveForGame($game, $awayTeamId);

        $baseline = (float) config('mlb.bullpen_ratings.baseline_rating', 100.0);
        $homeRating = (float) ($home['rating_score'] ?? $baseline);
        $awayRating = (float) ($away['rating_score'] ?? $baseline);
        $scoreDivisor = max(1.0, (float) config('mlb.prediction.situational.bullpen_quality.score_divisor', 18.0));

        $homeCentered = ($homeRating - $baseline) / $scoreDivisor;
        $awayCentered = ($awayRating - $baseline) / $scoreDivisor;

        $spreadAdjustment = ($homeCentered - $awayCentered)
            * (float) config('mlb.prediction.situational.bullpen_quality.spread_weight', 0.24);
        $totalAdjustment = -($homeCentered + $awayCentered)
            * (float) config('mlb.prediction.situational.bullpen_quality.total_weight', 0.14);

        return [
            'home_rating' => round($homeRating, 3),
            'away_rating' => round($awayRating, 3),
            'home_rank' => $home['rating_rank'] ?? null,
            'away_rank' => $away['rating_rank'] ?? null,
            'home_source' => $home['source'] ?? null,
            'away_source' => $away['source'] ?? null,
            'spread_adjustment' => round($spreadAdjustment, 2),
            'total_adjustment' => round($totalAdjustment, 2),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function calculateSnapshot(int $teamId, int $season, int|string $seasonType, CarbonInterface|string $asOfDate): ?array
    {
        $date = Carbon::parse($asOfDate)->toDateString();
        $stats = $this->statsForSnapshot($teamId, $season, $seasonType, $date);

        if ($stats->isEmpty()) {
            return null;
        }

        $decay = (float) config('mlb.bullpen_ratings.recency_decay', 0.82);
        $totals = [
            'weight' => 0.0,
            'innings' => 0.0,
            'earned_runs' => 0.0,
            'hits_walks' => 0.0,
            'strikeouts' => 0.0,
            'walks' => 0.0,
            'home_runs' => 0.0,
            'usage' => 0.0,
            'recent_form_weight' => 0.0,
            'recent_form_score' => 0.0,
            'workload_penalty' => 0.0,
            'workload_weight' => 0.0,
            'games_sampled' => 0,
        ];

        foreach ($stats->values() as $index => $stat) {
            $innings = $this->normalizeInningsPitched($stat->innings_pitched);
            $estimatedPitchersUsed = $this->estimatedPitchersUsed(
                $stat->pitchers_used,
                $stat->total_pitches,
                $stat->innings_pitched
            );
            $usageFactor = $this->usageFactor($estimatedPitchersUsed);

            if ($innings <= 0.0 || $usageFactor <= 0.0) {
                continue;
            }

            $recencyWeight = $index === 0 ? 1.0 : pow($decay, $index);
            $weight = $recencyWeight * $usageFactor;

            $totals['weight'] += $weight;
            $totals['innings'] += $innings * $weight;
            $totals['earned_runs'] += (float) ($stat->earned_runs ?? 0) * $weight;
            $totals['hits_walks'] += ((float) ($stat->hits_allowed ?? 0) + (float) ($stat->walks_allowed ?? 0)) * $weight;
            $totals['strikeouts'] += (float) ($stat->strikeouts_pitched ?? 0) * $weight;
            $totals['walks'] += (float) ($stat->walks_allowed ?? 0) * $weight;
            $totals['home_runs'] += (float) ($stat->home_runs_allowed ?? 0) * $weight;
            $totals['usage'] += $usageFactor * $recencyWeight;
            $totals['games_sampled']++;

            $gameEra = $innings > 0 ? (((float) ($stat->earned_runs ?? 0)) * 9.0) / $innings : null;
            $gameWhip = $innings > 0
                ? (((float) ($stat->hits_allowed ?? 0) + (float) ($stat->walks_allowed ?? 0)) / $innings)
                : null;
            $gameK9 = $innings > 0 ? (((float) ($stat->strikeouts_pitched ?? 0)) * 9.0) / $innings : null;
            $gameBb9 = $innings > 0 ? (((float) ($stat->walks_allowed ?? 0)) * 9.0) / $innings : null;
            $gameHr9 = $innings > 0 ? (((float) ($stat->home_runs_allowed ?? 0)) * 9.0) / $innings : null;

            $recentScore = $this->recentFormComponent($gameEra, $gameWhip, $gameK9, $gameBb9, $gameHr9);
            $totals['recent_form_score'] += $recentScore * $recencyWeight;
            $totals['recent_form_weight'] += $recencyWeight;

            if ($index < 3) {
                $totals['workload_penalty'] += $this->workloadPenalty(
                    $estimatedPitchersUsed,
                    (int) ($stat->total_pitches ?? 0)
                ) * $recencyWeight;
                $totals['workload_weight'] += $recencyWeight;
            }
        }

        if ($totals['innings'] <= 0.0 || $totals['games_sampled'] === 0) {
            return null;
        }

        $weightedEra = ($totals['earned_runs'] * 9.0) / $totals['innings'];
        $weightedWhip = $totals['hits_walks'] / $totals['innings'];
        $strikeoutsPerNine = ($totals['strikeouts'] * 9.0) / $totals['innings'];
        $walksPerNine = ($totals['walks'] * 9.0) / $totals['innings'];
        $homeRunsPerNine = ($totals['home_runs'] * 9.0) / $totals['innings'];
        $recentFormScore = $totals['recent_form_weight'] > 0
            ? $totals['recent_form_score'] / $totals['recent_form_weight']
            : 0.0;
        $workloadPenalty = $totals['workload_weight'] > 0
            ? $totals['workload_penalty'] / $totals['workload_weight']
            : 0.0;
        $weightedUsage = $totals['recent_form_weight'] > 0
            ? $totals['usage'] / $totals['recent_form_weight']
            : 0.0;

        $ratingScore = $this->ratingScore(
            $weightedEra,
            $weightedWhip,
            $strikeoutsPerNine,
            $walksPerNine,
            $homeRunsPerNine,
            $recentFormScore,
            $workloadPenalty
        );

        return [
            'season' => $season,
            'season_type' => (string) $seasonType,
            'as_of_date' => $date,
            'games_sampled' => $totals['games_sampled'],
            'weighted_usage' => round($weightedUsage, 3),
            'weighted_era' => round($weightedEra, 3),
            'weighted_whip' => round($weightedWhip, 3),
            'strikeouts_per_nine' => round($strikeoutsPerNine, 3),
            'walks_per_nine' => round($walksPerNine, 3),
            'home_runs_per_nine' => round($homeRunsPerNine, 3),
            'recent_form_score' => round($recentFormScore, 3),
            'workload_penalty' => round($workloadPenalty, 3),
            'rating_score' => round($ratingScore, 3),
            'calculation_date' => now()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveForGame(Game $game, int $teamId): ?array
    {
        $gameDate = $game->game_date instanceof CarbonInterface
            ? $game->game_date->toDateString()
            : Carbon::parse($game->game_date)->toDateString();

        $persisted = BullpenRating::query()
            ->where('team_id', $teamId)
            ->where('season', (int) $game->season)
            ->where('season_type', (string) $game->season_type)
            ->whereDate('as_of_date', '<=', $gameDate)
            ->orderByDesc('as_of_date')
            ->orderByDesc('id')
            ->first();

        if ($persisted) {
            return [
                'rating_score' => (float) $persisted->rating_score,
                'rating_rank' => $persisted->rating_rank,
                'source' => 'persisted',
            ];
        }

        $calculated = $this->calculateSnapshot($teamId, (int) $game->season, (string) $game->season_type, $gameDate);

        if ($calculated === null) {
            return null;
        }

        return [
            'rating_score' => (float) $calculated['rating_score'],
            'rating_rank' => null,
            'source' => 'calculated',
        ];
    }

    /**
     * @return Collection<int, TeamStat>
     */
    private function statsForSnapshot(int $teamId, int $season, int|string $seasonType, string $asOfDate): Collection
    {
        return TeamStat::query()
            ->where('team_id', $teamId)
            ->join('mlb_games', 'mlb_team_stats.game_id', '=', 'mlb_games.id')
            ->where('mlb_games.season', $season)
            ->where('mlb_games.season_type', (string) $seasonType)
            ->where('mlb_games.status', (string) config('mlb.statuses.final', 'STATUS_FINAL'))
            ->whereDate('mlb_games.game_date', '<', $asOfDate)
            ->orderByDesc('mlb_games.game_date')
            ->orderByDesc('mlb_games.id')
            ->select('mlb_team_stats.*')
            ->limit((int) config('mlb.bullpen_ratings.lookback_games', 12))
            ->get();
    }

    private function usageFactor(int $pitchersUsed): float
    {
        if ($pitchersUsed <= 1) {
            return 0.0;
        }

        return min(1.0, max(0.0, ($pitchersUsed - 1) / 4));
    }

    private function workloadPenalty(int $pitchersUsed, int $totalPitches): float
    {
        $pitcherLoad = max(0.0, $pitchersUsed - 3) * 0.16;
        $pitchLoad = max(0.0, $totalPitches - 135) / 55;

        return min(1.5, $pitcherLoad + $pitchLoad);
    }

    private function recentFormComponent(
        ?float $era,
        ?float $whip,
        ?float $k9,
        ?float $bb9,
        ?float $hr9
    ): float {
        if ($era === null || $whip === null || $k9 === null || $bb9 === null || $hr9 === null) {
            return 0.0;
        }

        $score = 0.0;
        $score += ((float) config('mlb.bullpen_ratings.baselines.era', 4.10) - $era)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.era', 0.45));
        $score += ((float) config('mlb.bullpen_ratings.baselines.whip', 1.28) - $whip)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.whip', 0.06));
        $score += ($k9 - (float) config('mlb.bullpen_ratings.baselines.k_per_nine', 8.8))
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.k_per_nine', 0.8));
        $score += ((float) config('mlb.bullpen_ratings.baselines.bb_per_nine', 3.5) - $bb9)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.bb_per_nine', 0.5));
        $score += ((float) config('mlb.bullpen_ratings.baselines.hr_per_nine', 1.1) - $hr9)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.hr_per_nine', 0.25));

        return max(-2.0, min(2.0, $score / 5.0));
    }

    private function ratingScore(
        float $weightedEra,
        float $weightedWhip,
        float $strikeoutsPerNine,
        float $walksPerNine,
        float $homeRunsPerNine,
        float $recentFormScore,
        float $workloadPenalty
    ): float {
        $baseline = (float) config('mlb.bullpen_ratings.baseline_rating', 100.0);

        $score = $baseline;
        $score += (((float) config('mlb.bullpen_ratings.baselines.era', 4.10) - $weightedEra)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.era', 0.45)))
            * (float) config('mlb.bullpen_ratings.weights.era', 7.0);
        $score += (((float) config('mlb.bullpen_ratings.baselines.whip', 1.28) - $weightedWhip)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.whip', 0.06)))
            * (float) config('mlb.bullpen_ratings.weights.whip', 6.0);
        $score += (($strikeoutsPerNine - (float) config('mlb.bullpen_ratings.baselines.k_per_nine', 8.8))
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.k_per_nine', 0.8)))
            * (float) config('mlb.bullpen_ratings.weights.k_per_nine', 2.5);
        $score += (((float) config('mlb.bullpen_ratings.baselines.bb_per_nine', 3.5) - $walksPerNine)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.bb_per_nine', 0.5)))
            * (float) config('mlb.bullpen_ratings.weights.bb_per_nine', 2.5);
        $score += (((float) config('mlb.bullpen_ratings.baselines.hr_per_nine', 1.1) - $homeRunsPerNine)
            / max(0.01, (float) config('mlb.bullpen_ratings.divisors.hr_per_nine', 0.25)))
            * (float) config('mlb.bullpen_ratings.weights.hr_per_nine', 2.0);
        $score += $recentFormScore * (float) config('mlb.bullpen_ratings.weights.recent_form', 4.0);
        $score -= $workloadPenalty * (float) config('mlb.bullpen_ratings.weights.workload_penalty', 2.0);

        return min(
            (float) config('mlb.bullpen_ratings.max_rating', 140.0),
            max((float) config('mlb.bullpen_ratings.min_rating', 60.0), $score)
        );
    }

    private function normalizeInningsPitched(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $whole = (int) floor($number);
            $fractionDigit = (int) round(($number - $whole) * 10);

            if ($fractionDigit === 1 || $fractionDigit === 2) {
                return $whole + ($fractionDigit / 3);
            }

            return $number;
        }

        $text = trim((string) $value);
        if (! preg_match('/^(\d+)(?:\.(\d))?$/', $text, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];
        $fractionDigit = isset($matches[2]) ? (int) $matches[2] : 0;

        if ($fractionDigit === 1 || $fractionDigit === 2) {
            return $whole + ($fractionDigit / 3);
        }

        return (float) $whole;
    }

    private function estimatedPitchersUsed(mixed $pitchersUsed, mixed $pitchCount, mixed $inningsPitched): int
    {
        if (is_numeric($pitchersUsed) && (int) $pitchersUsed >= 2) {
            return (int) $pitchersUsed;
        }

        $pitchCount = is_numeric($pitchCount) ? (int) $pitchCount : null;
        if ($pitchCount !== null) {
            return match (true) {
                $pitchCount >= 165 => 5,
                $pitchCount >= 145 => 4,
                $pitchCount >= 125 => 3,
                $pitchCount >= 108 => 2,
                default => 1,
            };
        }

        $innings = $this->normalizeInningsPitched($inningsPitched);

        if ($innings !== null && $innings < 8.0) {
            return 2;
        }

        return 1;
    }
}
