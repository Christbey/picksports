<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use Carbon\CarbonInterface;

class NflBettingSignalService
{
    public function __construct(
        protected TeamPlayoffForecastService $forecastService,
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function signals(int $season, ?CarbonInterface $asOfDate = null): array
    {
        $asOfDate ??= now();
        $weekOneGames = $this->weekOnePredictionRows($season);

        return [
            'season' => $season,
            'as_of_date' => $asOfDate->toDateString(),
            'super_bowl' => $this->superBowlSignals($season, $asOfDate),
            'week_one_winners' => $this->weekOneWinnerSignals($weekOneGames),
            'week_one_covers' => $this->weekOneCoverSignals($weekOneGames),
            'streaks' => $this->streakSignals($season, $asOfDate),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function superBowlSignals(int $season, CarbonInterface $asOfDate): array
    {
        $report = $this->forecastService->forecast($season, $asOfDate->toDateString());
        $rows = array_values((array) ($report['teams'] ?? []));
        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('nfl', $season);

        $rows = array_map(function (array $row) use ($marketOddsByTeam): array {
            $teamId = (int) ($row['team_id'] ?? 0);
            $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

            return $row;
        }, $rows);
        $rows = $this->futuresEdgeService->annotate($rows, 'super_bowl_champion_probability');

        usort($rows, fn (array $left, array $right): int => $this->superBowlSortScore($right) <=> $this->superBowlSortScore($left));

        return array_map(fn (array $row): array => [
            'type' => 'super_bowl',
            'team_id' => (int) ($row['team_id'] ?? 0),
            'team_name' => (string) ($row['team_name'] ?? ''),
            'conference' => $row['conference'] ?? null,
            'division' => $row['division'] ?? null,
            'probability' => (float) ($row['super_bowl_champion_probability'] ?? 0.0),
            'projected_wins' => (float) ($row['projected_wins'] ?? 0.0),
            'market_odds' => $row['market_odds'] ?? null,
            'market_edge' => $row['market_edge'] ?? null,
            'signal' => $this->superBowlSignalLabel($row),
            'reason_codes' => $this->superBowlReasonCodes($row),
        ], array_slice($rows, 0, 10));
    }

    protected function superBowlSortScore(array $row): float
    {
        return ((float) ($row['super_bowl_champion_probability'] ?? 0.0) * 1000)
            + ((float) data_get($row, 'market_edge.edge_percent_points', 0.0) * 0.5);
    }

    protected function superBowlSignalLabel(array $row): string
    {
        $probability = (float) ($row['super_bowl_champion_probability'] ?? 0.0);
        $edge = (float) data_get($row, 'market_edge.edge_percent_points', 0.0);

        return match (true) {
            $edge >= 3.0 && $probability >= 0.06 => 'value_contender',
            $probability >= 0.10 => 'model_contender',
            $edge >= 2.0 => 'value_watchlist',
            default => 'watchlist',
        };
    }

    /**
     * @return list<string>
     */
    protected function superBowlReasonCodes(array $row): array
    {
        $codes = ['super_bowl_futures_signal'];
        $probability = (float) ($row['super_bowl_champion_probability'] ?? 0.0);
        $projectedWins = (float) ($row['projected_wins'] ?? 0.0);
        $edge = (float) data_get($row, 'market_edge.edge_percent_points', 0.0);

        if ($probability >= 0.10) {
            $codes[] = 'super_bowl_model_contender';
        } elseif ($probability >= 0.06) {
            $codes[] = 'super_bowl_secondary_contender';
        }

        if ($projectedWins >= 11.0) {
            $codes[] = 'elite_projected_win_total';
        }

        if ($edge >= 3.0) {
            $codes[] = 'super_bowl_market_value';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<int,Prediction>
     */
    protected function weekOnePredictionRows(int $season): array
    {
        return Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) use ($season): void {
                $query->where('season', $season)
                    ->whereIn('season_type', ['2', 2, 'Regular Season'])
                    ->where('week', 1);
            })
            ->get()
            ->filter(fn (Prediction $prediction): bool => $prediction->game !== null)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function weekOneWinnerSignals(array $predictions): array
    {
        $signals = array_map(function (Prediction $prediction): array {
            $game = $prediction->game;
            $homeWinProbability = (float) $prediction->win_probability;
            $pickSide = $homeWinProbability >= 0.5 ? 'home' : 'away';
            $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
            $analysis = (array) data_get($prediction->model_metadata, 'analysis_layer', []);

            return [
                'type' => 'week_one_winner',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'pick_side' => $pickSide,
                'team_id' => (int) ($pickTeam?->id ?? 0),
                'team_name' => $this->teamName($pickTeam),
                'win_probability' => round(max($homeWinProbability, 1 - $homeWinProbability), 4),
                'trust_score' => data_get($analysis, 'trust_score') !== null ? (float) data_get($analysis, 'trust_score') : null,
                'bet_classification' => $analysis['bet_classification'] ?? null,
                'reason_codes' => array_values(array_unique(array_merge(
                    ['week_one_winner_signal'],
                    array_slice((array) ($analysis['reason_codes'] ?? []), 0, 8),
                ))),
            ];
        }, $predictions);

        usort($signals, fn (array $left, array $right): int => ($right['win_probability'] <=> $left['win_probability'])
            ?: (($right['trust_score'] ?? 0) <=> ($left['trust_score'] ?? 0)));

        return array_slice($signals, 0, 10);
    }

    /**
     * @param  array<int,Prediction>  $predictions
     * @return array<int,array<string,mixed>>
     */
    protected function weekOneCoverSignals(array $predictions): array
    {
        $signals = [];

        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            $marketSpread = data_get($prediction->model_metadata, 'analysis_layer.calculated_edge.market_spread');
            if (! is_numeric($marketSpread)) {
                continue;
            }

            $predictedSpread = (float) $prediction->predicted_spread;
            $spreadEdge = $predictedSpread - (float) $marketSpread;
            if (abs($spreadEdge) < (float) config('nfl.signals.week_one_cover_min_edge', 1.5)) {
                continue;
            }

            $pickSide = $spreadEdge > 0 ? 'home' : 'away';
            $pickTeam = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
            $analysis = (array) data_get($prediction->model_metadata, 'analysis_layer', []);

            $signals[] = [
                'type' => 'week_one_cover',
                'game_id' => (int) $game->id,
                'game_date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
                'pick_side' => $pickSide,
                'team_id' => (int) ($pickTeam?->id ?? 0),
                'team_name' => $this->teamName($pickTeam),
                'predicted_spread' => round($predictedSpread, 2),
                'market_spread' => round((float) $marketSpread, 2),
                'edge_points' => round(abs($spreadEdge), 2),
                'trust_score' => data_get($analysis, 'trust_score') !== null ? (float) data_get($analysis, 'trust_score') : null,
                'bet_classification' => $analysis['bet_classification'] ?? null,
                'reason_codes' => array_values(array_unique(array_merge(
                    ['week_one_cover_signal', $pickSide.'_week_one_cover_edge'],
                    array_slice((array) ($analysis['reason_codes'] ?? []), 0, 8),
                ))),
            ];
        }

        usort($signals, fn (array $left, array $right): int => ($right['edge_points'] <=> $left['edge_points'])
            ?: (($right['trust_score'] ?? 0) <=> ($left['trust_score'] ?? 0)));

        return array_slice($signals, 0, 10);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function streakSignals(int $season, CarbonInterface $asOfDate): array
    {
        $signals = [];

        foreach (Team::query()->orderBy('abbreviation')->get() as $team) {
            foreach ([null, 'ats', 'total'] as $market) {
                $streak = $this->teamResultStreak((int) $team->id, $asOfDate, $market);
                if ($streak === null || (int) $streak['length'] < (int) config('nfl.signals.min_streak_length', 3)) {
                    continue;
                }

                $signals[] = [
                    ...$streak,
                    'team_id' => (int) $team->id,
                    'team_name' => $this->teamName($team),
                    'team_abbreviation' => (string) ($team->abbreviation ?? ''),
                    'season_context' => $season,
                ];
            }
        }

        usort($signals, fn (array $left, array $right): int => ($right['length'] <=> $left['length'])
            ?: strcmp((string) $left['team_abbreviation'], (string) $right['team_abbreviation']));

        return array_slice($signals, 0, 16);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function teamResultStreak(int $teamId, CarbonInterface $asOfDate, ?string $market): ?array
    {
        $games = Game::query()
            ->with('prediction')
            ->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '<=', $asOfDate->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        $streakKey = null;
        $length = 0;
        $sample = [];

        foreach ($games as $game) {
            $result = match ($market) {
                'ats' => $this->atsResult($game, $teamId),
                'total' => $this->totalResult($game),
                default => $this->straightUpResult($game, $teamId),
            };

            if ($result === null) {
                continue;
            }

            $streakKey ??= $result;
            if ($result !== $streakKey) {
                break;
            }

            $length++;
            $sample[] = [
                'game_id' => (int) $game->id,
                'date' => $game->game_date?->toDateString(),
                'matchup' => (string) ($game->short_name ?: $game->name),
            ];
        }

        if ($streakKey === null || $length === 0) {
            return null;
        }

        return [
            'type' => match ($market) {
                'ats' => 'ats_streak',
                'total' => 'total_streak',
                default => 'straight_up_streak',
            },
            'streak' => $streakKey,
            'length' => $length,
            'label' => $this->streakLabel($market, $streakKey, $length),
            'reason_codes' => $this->streakReasonCodes($market, $streakKey),
            'sample_games' => $sample,
        ];
    }

    protected function straightUpResult(Game $game, int $teamId): ?string
    {
        if ($game->home_score === null || $game->away_score === null || (int) $game->home_score === (int) $game->away_score) {
            return null;
        }

        $homeWon = (int) $game->home_score > (int) $game->away_score;

        return ($game->home_team_id === $teamId) === $homeWon ? 'win' : 'loss';
    }

    protected function atsResult(Game $game, int $teamId): ?string
    {
        $marketSpread = data_get($game->prediction?->model_metadata, 'analysis_layer.calculated_edge.market_spread');
        if ($game->home_score === null || $game->away_score === null || ! is_numeric($marketSpread)) {
            return null;
        }

        $homeMargin = (int) $game->home_score - (int) $game->away_score;
        $homeCovered = $homeMargin > (float) $marketSpread;

        return ($game->home_team_id === $teamId) === $homeCovered ? 'cover' : 'failed_cover';
    }

    protected function totalResult(Game $game): ?string
    {
        $marketTotal = data_get($game->prediction?->model_metadata, 'analysis_layer.calculated_edge.market_total');
        if ($game->home_score === null || $game->away_score === null || ! is_numeric($marketTotal)) {
            return null;
        }

        $actualTotal = (int) $game->home_score + (int) $game->away_score;

        return $actualTotal > (float) $marketTotal ? 'over' : 'under';
    }

    protected function streakLabel(?string $market, string $streakKey, int $length): string
    {
        return match ($market) {
            'ats' => $streakKey === 'cover' ? "{$length}-game cover streak" : "{$length}-game failed-cover streak",
            'total' => "{$length}-game {$streakKey} streak",
            default => "{$length}-game {$streakKey} streak",
        };
    }

    /**
     * @return list<string>
     */
    protected function streakReasonCodes(?string $market, string $streakKey): array
    {
        return array_values(array_filter([
            'streak_watch_signal',
            $market === 'ats' ? 'ats_streak_context' : null,
            $market === 'total' ? 'total_streak_context' : null,
            $market === null ? 'straight_up_streak_context' : null,
            str_replace('-', '_', $streakKey).'_streak',
        ]));
    }

    protected function teamName(?Team $team): string
    {
        if (! $team) {
            return '';
        }

        $display = trim((string) ($team->display_name ?? ''));
        if ($display !== '') {
            return $display;
        }

        $name = trim(implode(' ', array_filter([$team->location, $team->name])));

        return $name !== '' ? $name : (string) ($team->abbreviation ?? $team->id);
    }
}
