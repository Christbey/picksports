<?php

namespace App\Services\NFL;

use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TeamOffseasonSignalService
{
    /**
     * @return array<int, array<string, float>>
     */
    public function signalsForSeason(int $season, CarbonInterface|string $asOfDate): array
    {
        $timestamp = Carbon::parse($asOfDate);
        $priorSeason = $season - 1;
        $olderSeason = $season - 2;

        if ($priorSeason <= 0) {
            return [];
        }

        $priorUsage = $this->usageByTeamAndPlayer($priorSeason);
        $olderUsage = $olderSeason > 0 ? $this->usageByTeamAndPlayer($olderSeason) : [];
        $activeInjuries = $this->activeInjuriesByTeam($timestamp);

        $teamIds = array_values(array_unique([
            ...array_keys($priorUsage),
            ...array_keys($olderUsage),
            ...array_keys($activeInjuries),
        ]));

        $signals = [];
        foreach ($teamIds as $teamId) {
            $teamPriorUsage = $priorUsage[$teamId] ?? [];
            $teamOlderUsage = $olderUsage[$teamId] ?? [];

            $qbContinuity = $this->qbContinuitySignal($teamPriorUsage, $teamOlderUsage);
            [$skillContinuity, $returningProductionShare] = $this->skillContinuitySignal($teamPriorUsage, $teamOlderUsage);
            $injuryAdjustment = $this->injuryAdjustment($teamPriorUsage, $activeInjuries[$teamId] ?? []);

            $offseasonAdjustment = ($qbContinuity * (float) config('nfl.team_futures.offseason_qb_continuity_weight', 2.0))
                + ($skillContinuity * (float) config('nfl.team_futures.offseason_skill_continuity_weight', 1.25));

            $signals[$teamId] = [
                'qb_continuity_signal' => round($qbContinuity, 4),
                'skill_continuity_signal' => round($skillContinuity, 4),
                'returning_production_share' => round($returningProductionShare, 4),
                'injury_adjustment' => round($injuryAdjustment, 4),
                'offseason_adjustment' => round($offseasonAdjustment, 4),
            ];
        }

        return $signals;
    }

    /**
     * @return array<int, array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>>
     */
    protected function usageByTeamAndPlayer(int $season): array
    {
        $rows = PlayerStat::query()
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->join('nfl_players', 'nfl_players.id', '=', 'nfl_player_stats.player_id')
            ->where('nfl_games.season', $season)
            ->where('nfl_games.status', config('nfl.statuses.final'))
            ->where(function ($query): void {
                $query->whereNull('nfl_games.season_type')
                    ->orWhere('nfl_games.season_type', config('nfl.season.types.regular', 2));
            })
            ->selectRaw('
                nfl_player_stats.team_id,
                nfl_player_stats.player_id,
                nfl_players.position,
                COALESCE(SUM(nfl_player_stats.passing_attempts), 0) as passing_attempts,
                COALESCE(SUM(nfl_player_stats.rushing_attempts), 0) + COALESCE(SUM(nfl_player_stats.receiving_targets), 0) as skill_opportunities
            ')
            ->groupBy('nfl_player_stats.team_id', 'nfl_player_stats.player_id', 'nfl_players.position')
            ->get();

        $byTeam = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row['team_id'] ?? 0);
            $playerId = (int) ($row['player_id'] ?? 0);
            if ($teamId <= 0 || $playerId <= 0) {
                continue;
            }

            $byTeam[$teamId][$playerId] = [
                'position' => (string) ($row['position'] ?? ''),
                'passing_attempts' => (float) ($row['passing_attempts'] ?? 0.0),
                'skill_opportunities' => (float) ($row['skill_opportunities'] ?? 0.0),
            ];
        }

        return $byTeam;
    }

    /**
     * @return array<int, array<int, array{player_id:int,position:string,status:string}>>
     */
    protected function activeInjuriesByTeam(CarbonInterface $asOfDate): array
    {
        $rows = PlayerInjury::query()
            ->with('player:id,position')
            ->where('is_active', true)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('source_updated_at')
                    ->orWhere('source_updated_at', '<=', $asOfDate);
            })
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('injury_date')
                    ->orWhere('injury_date', '<=', $asOfDate->toDateString());
            })
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('return_date')
                    ->orWhere('return_date', '>=', $asOfDate->toDateString());
            })
            ->get(['team_id', 'player_id', 'status']);

        $byTeam = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row->team_id ?? 0);
            $playerId = (int) ($row->player_id ?? 0);
            if ($teamId <= 0 || $playerId <= 0) {
                continue;
            }

            $byTeam[$teamId][$playerId] = [
                'player_id' => $playerId,
                'position' => (string) ($row->player?->position ?? ''),
                'status' => (string) ($row->status ?? ''),
            ];
        }

        return $byTeam;
    }

    /**
     * @param  array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>  $priorUsage
     * @param  array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>  $olderUsage
     */
    protected function qbContinuitySignal(array $priorUsage, array $olderUsage): float
    {
        $priorQuarterbacks = array_filter($priorUsage, fn (array $usage): bool => strtoupper((string) ($usage['position'] ?? '')) === 'QB');
        $olderQuarterbacks = array_filter($olderUsage, fn (array $usage): bool => strtoupper((string) ($usage['position'] ?? '')) === 'QB');

        if ($priorQuarterbacks === [] || $olderQuarterbacks === []) {
            return 0.0;
        }

        uasort($priorQuarterbacks, fn (array $a, array $b): int => $b['passing_attempts'] <=> $a['passing_attempts']);
        uasort($olderQuarterbacks, fn (array $a, array $b): int => $b['passing_attempts'] <=> $a['passing_attempts']);

        $priorLeaderId = (int) array_key_first($priorQuarterbacks);
        $olderLeaderId = (int) array_key_first($olderQuarterbacks);
        $priorTotalAttempts = array_sum(array_column($priorQuarterbacks, 'passing_attempts'));
        $olderTotalAttempts = array_sum(array_column($olderQuarterbacks, 'passing_attempts'));

        if ($priorLeaderId <= 0 || $olderLeaderId <= 0 || $priorTotalAttempts <= 0.0 || $olderTotalAttempts <= 0.0) {
            return 0.0;
        }

        $priorShare = (float) ($priorQuarterbacks[$priorLeaderId]['passing_attempts'] ?? 0.0) / $priorTotalAttempts;
        $olderShare = (float) ($olderQuarterbacks[$olderLeaderId]['passing_attempts'] ?? 0.0) / $olderTotalAttempts;
        $dominance = min(1.0, max(0.0, ($priorShare + $olderShare) / 2.0));

        return $priorLeaderId === $olderLeaderId ? $dominance : -$dominance;
    }

    /**
     * @param  array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>  $priorUsage
     * @param  array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>  $olderUsage
     * @return array{0:float,1:float}
     */
    protected function skillContinuitySignal(array $priorUsage, array $olderUsage): array
    {
        $priorSkill = array_filter(
            $priorUsage,
            fn (array $usage): bool => strtoupper((string) ($usage['position'] ?? '')) !== 'QB' && (float) ($usage['skill_opportunities'] ?? 0.0) > 0.0
        );
        $olderSkill = array_filter(
            $olderUsage,
            fn (array $usage): bool => strtoupper((string) ($usage['position'] ?? '')) !== 'QB' && (float) ($usage['skill_opportunities'] ?? 0.0) > 0.0
        );

        if ($priorSkill === [] || $olderSkill === []) {
            return [0.0, 0.0];
        }

        uasort($priorSkill, fn (array $a, array $b): int => $b['skill_opportunities'] <=> $a['skill_opportunities']);
        uasort($olderSkill, fn (array $a, array $b): int => $b['skill_opportunities'] <=> $a['skill_opportunities']);

        $topPlayers = max(1, (int) config('nfl.team_futures.offseason_skill_top_players', 5));
        $priorTop = array_slice($priorSkill, 0, $topPlayers, true);
        $olderTopIds = array_keys(array_slice($olderSkill, 0, $topPlayers, true));
        $priorTopOpportunities = array_sum(array_column($priorTop, 'skill_opportunities'));

        if ($priorTopOpportunities <= 0.0) {
            return [0.0, 0.0];
        }

        $retained = 0.0;
        foreach ($priorTop as $playerId => $usage) {
            if (in_array($playerId, $olderTopIds, true)) {
                $retained += (float) ($usage['skill_opportunities'] ?? 0.0);
            }
        }

        $retainedShare = $retained / $priorTopOpportunities;
        $baseline = (float) config('nfl.team_futures.offseason_skill_overlap_baseline', 0.45);

        return [
            max(-1.0, min(1.0, $retainedShare - $baseline)),
            max(0.0, min(1.0, $retainedShare)),
        ];
    }

    /**
     * @param  array<int, array{position:string,passing_attempts:float,skill_opportunities:float}>  $priorUsage
     * @param  array<int, array{player_id:int,position:string,status:string}>  $injuries
     */
    protected function injuryAdjustment(array $priorUsage, array $injuries): float
    {
        if ($injuries === []) {
            return 0.0;
        }

        $quarterbackAttempts = array_sum(array_map(
            fn (array $usage): float => strtoupper((string) ($usage['position'] ?? '')) === 'QB' ? (float) ($usage['passing_attempts'] ?? 0.0) : 0.0,
            $priorUsage
        ));
        $skillOpportunities = array_sum(array_map(
            fn (array $usage): float => strtoupper((string) ($usage['position'] ?? '')) !== 'QB' ? (float) ($usage['skill_opportunities'] ?? 0.0) : 0.0,
            $priorUsage
        ));

        $penalty = 0.0;
        foreach ($injuries as $injury) {
            $playerId = (int) ($injury['player_id'] ?? 0);
            $position = strtoupper((string) ($injury['position'] ?? ''));
            $status = strtolower(trim((string) ($injury['status'] ?? '')));
            $usage = $priorUsage[$playerId] ?? null;

            if ($position === 'QB') {
                $usageShare = $usage !== null && $quarterbackAttempts > 0.0
                    ? ((float) ($usage['passing_attempts'] ?? 0.0) / $quarterbackAttempts)
                    : (float) config('nfl.team_futures.offseason_position_default_usage.qb', 0.35);
            } else {
                $usageShare = $usage !== null && $skillOpportunities > 0.0
                    ? ((float) ($usage['skill_opportunities'] ?? 0.0) / $skillOpportunities)
                    : (float) data_get(config('nfl.team_futures.offseason_position_default_usage', []), strtolower($position), 0.05);
            }

            $statusPenalty = (float) data_get(config('nfl.team_futures.offseason_injury_status_penalties', []), $status, 0.25);
            $positionWeight = (float) data_get(config('nfl.team_futures.offseason_injury_position_weights', []), strtolower($position), 1.0);

            $penalty += $usageShare * $statusPenalty * $positionWeight;
        }

        $cap = max(0.0, (float) config('nfl.team_futures.offseason_max_injury_adjustment', 0.75));

        return -min($cap, $penalty);
    }
}
