<?php

namespace App\Services\Sports;

use App\Models\Sports\FuturesOdd;
use App\Models\Sports\FuturesOddsSnapshot;
use Carbon\CarbonInterface;

class FuturesOddsLookupService
{
    /**
     * @var array<string, string>
     */
    protected const TEAM_FOREIGN_KEY_BY_SPORT = [
        'nba' => 'nba_team_id',
        'mlb' => 'mlb_team_id',
        'nfl' => 'nfl_team_id',
        'cbb' => 'cbb_team_id',
        'wcbb' => 'wcbb_team_id',
    ];

    /**
     * @return array<int, array{bookmaker:string,market_key:string,price:?int,implied_probability:?float,fetched_at:?string,odds_api_sport_key:string}>
     */
    public function byTeamForSeason(string $sport, int $season, array|string|null $marketKeys = null): array
    {
        $teamForeignKey = self::TEAM_FOREIGN_KEY_BY_SPORT[$sport] ?? null;
        if ($teamForeignKey === null) {
            return [];
        }

        $resolvedMarketKeys = $this->resolvedMarketKeys($marketKeys);

        $rows = FuturesOdd::query()
            ->where('sport', $sport)
            ->where('season', $season)
            ->whereNotNull($teamForeignKey)
            ->when($resolvedMarketKeys !== [], fn ($query) => $query->whereIn('market_key', $resolvedMarketKeys))
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get([
                $teamForeignKey,
                'bookmaker',
                'market_key',
                'price',
                'implied_probability',
                'fetched_at',
                'odds_api_sport_key',
            ]);

        $byTeam = [];

        foreach ($rows as $row) {
            $teamId = (int) ($row->{$teamForeignKey} ?? 0);
            if ($teamId <= 0 || isset($byTeam[$teamId])) {
                continue;
            }

            $byTeam[$teamId] = [
                'bookmaker' => (string) $row->bookmaker,
                'market_key' => (string) $row->market_key,
                'price' => $row->price !== null ? (int) $row->price : null,
                'implied_probability' => $row->implied_probability !== null ? (float) $row->implied_probability : null,
                'fetched_at' => $row->fetched_at?->toIso8601String(),
                'odds_api_sport_key' => (string) $row->odds_api_sport_key,
            ];
        }

        return $byTeam;
    }

    /**
     * @return array<int, string>
     */
    public function championshipMarketKeys(): array
    {
        return ['championship_winner', 'outrights'];
    }

    /**
     * @return array<int, array{bookmaker:string,market_key:string,price:?int,implied_probability:?float,captured_at:?string,odds_api_sport_key:string}>
     */
    public function byTeamForSeasonAt(string $sport, int $season, CarbonInterface|string $capturedAt): array
    {
        $teamForeignKey = self::TEAM_FOREIGN_KEY_BY_SPORT[$sport] ?? null;
        if ($teamForeignKey === null) {
            return [];
        }

        $rows = FuturesOddsSnapshot::query()
            ->where('sport', $sport)
            ->where('season', $season)
            ->whereNotNull($teamForeignKey)
            ->where('captured_at', '<=', $capturedAt)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get([
                $teamForeignKey,
                'bookmaker',
                'market_key',
                'price',
                'implied_probability',
                'captured_at',
                'odds_api_sport_key',
            ]);

        $byTeam = [];

        foreach ($rows as $row) {
            $teamId = (int) ($row->{$teamForeignKey} ?? 0);
            if ($teamId <= 0 || isset($byTeam[$teamId])) {
                continue;
            }

            $byTeam[$teamId] = [
                'bookmaker' => (string) $row->bookmaker,
                'market_key' => (string) $row->market_key,
                'price' => $row->price !== null ? (int) $row->price : null,
                'implied_probability' => $row->implied_probability !== null ? (float) $row->implied_probability : null,
                'captured_at' => $row->captured_at?->toIso8601String(),
                'odds_api_sport_key' => (string) $row->odds_api_sport_key,
            ];
        }

        return $byTeam;
    }

    /**
     * @return array<int, string>
     */
    public function snapshotDatesForSeason(string $sport, int $season): array
    {
        return FuturesOddsSnapshot::query()
            ->where('sport', $sport)
            ->where('season', $season)
            ->orderByDesc('captured_at')
            ->distinct()
            ->pluck('captured_at')
            ->filter()
            ->map(fn ($timestamp) => $timestamp instanceof \DateTimeInterface
                ? $timestamp->format(\DateTimeInterface::ATOM)
                : (string) $timestamp)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function snapshotDatesForSeasonMarket(string $sport, int $season, array|string $marketKeys): array
    {
        $resolvedMarketKeys = $this->resolvedMarketKeys($marketKeys);

        $query = FuturesOddsSnapshot::query()
            ->where('sport', $sport)
            ->where('season', $season);

        if ($resolvedMarketKeys !== []) {
            $query->whereIn('market_key', $resolvedMarketKeys);
        }

        return $query
            ->orderBy('captured_at')
            ->distinct()
            ->pluck('captured_at')
            ->filter()
            ->map(fn ($timestamp) => $timestamp instanceof \DateTimeInterface
                ? $timestamp->format(\DateTimeInterface::ATOM)
                : (string) $timestamp)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function resolvedMarketKeys(array|string|null $marketKeys): array
    {
        if ($marketKeys === null) {
            return [];
        }

        $resolvedMarketKeys = is_array($marketKeys) ? $marketKeys : [$marketKeys];

        return array_values(array_unique(array_filter(array_map(
            static fn ($marketKey) => trim((string) $marketKey),
            $resolvedMarketKeys
        ))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nflTeamWinTotalsBySeasonAt(int $season, CarbonInterface|string $capturedAt, ?array $marketKeys = null): array
    {
        $resolvedMarketKeys = $marketKeys ?? ['season_wins'];

        $rows = FuturesOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('season', $season)
            ->whereNotNull('nfl_team_id')
            ->whereNotNull('outcome_point')
            ->where('captured_at', '<=', $capturedAt)
            ->whereIn('market_key', $resolvedMarketKeys)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get([
                'nfl_team_id',
                'bookmaker',
                'market_key',
                'price',
                'implied_probability',
                'captured_at',
                'odds_api_sport_key',
                'outcome_name',
                'outcome_description',
                'outcome_point',
            ]);

        $byTeam = [];

        foreach ($rows as $row) {
            $teamId = (int) ($row->nfl_team_id ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $side = strtolower(trim((string) $row->outcome_name));
            if (! in_array($side, ['over', 'under'], true)) {
                continue;
            }

            $line = $row->outcome_point !== null ? (float) $row->outcome_point : null;
            if ($line === null) {
                continue;
            }

            $existing = $byTeam[$teamId] ?? null;

            if ($existing !== null && (float) ($existing['line'] ?? 0.0) !== $line) {
                continue;
            }

            if ($existing !== null && (
                (string) ($existing['bookmaker'] ?? '') !== (string) $row->bookmaker
                || (string) ($existing['market_key'] ?? '') !== (string) $row->market_key
                || (string) ($existing['captured_at'] ?? '') !== (string) $row->captured_at?->toIso8601String()
            )) {
                continue;
            }

            if ($existing === null) {
                $byTeam[$teamId] = [
                    'bookmaker' => (string) $row->bookmaker,
                    'market_key' => (string) $row->market_key,
                    'line' => $line,
                    'over_price' => null,
                    'under_price' => null,
                    'over_implied_probability' => null,
                    'under_implied_probability' => null,
                    'over_no_vig_probability' => null,
                    'under_no_vig_probability' => null,
                    'overround' => null,
                    'is_complete_market' => false,
                    'captured_at' => $row->captured_at?->toIso8601String(),
                    'odds_api_sport_key' => (string) $row->odds_api_sport_key,
                    'team_name' => (string) ($row->outcome_description ?: ''),
                ];
            }

            $byTeam[$teamId]["{$side}_price"] = $row->price !== null ? (int) $row->price : null;
            $byTeam[$teamId]["{$side}_implied_probability"] = $row->implied_probability !== null
                ? (float) $row->implied_probability
                : null;
        }

        foreach ($byTeam as $teamId => $market) {
            $over = $market['over_implied_probability'] ?? null;
            $under = $market['under_implied_probability'] ?? null;

            if (! is_numeric($over) || ! is_numeric($under)) {
                continue;
            }

            $overround = (float) $over + (float) $under;
            if ($overround <= 0.0) {
                continue;
            }

            $byTeam[$teamId]['over_no_vig_probability'] = round((float) $over / $overround, 6);
            $byTeam[$teamId]['under_no_vig_probability'] = round((float) $under / $overround, 6);
            $byTeam[$teamId]['overround'] = round($overround, 6);
            $byTeam[$teamId]['is_complete_market'] = true;
        }

        return $byTeam;
    }

    /**
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function nflPlayerSeasonTotalsBySeason(int $season): array
    {
        $rows = FuturesOdd::query()
            ->where('sport', 'nfl')
            ->where('season', $season)
            ->whereNotNull('nfl_player_id')
            ->whereNotNull('outcome_point')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get([
                'nfl_player_id',
                'bookmaker',
                'market_key',
                'price',
                'implied_probability',
                'fetched_at',
                'odds_api_sport_key',
                'outcome_name',
                'outcome_description',
                'outcome_point',
            ]);

        $byPlayer = [];

        foreach ($rows as $row) {
            $playerId = (int) ($row->nfl_player_id ?? 0);
            $market = $this->normalizeNflPlayerTotalsMarket((string) $row->market_key);

            if ($playerId <= 0 || $market === null) {
                continue;
            }

            $side = strtolower(trim((string) $row->outcome_name));
            if (! in_array($side, ['over', 'under'], true)) {
                continue;
            }

            $existing = $byPlayer[$playerId][$market] ?? null;
            $line = $row->outcome_point !== null ? (float) $row->outcome_point : null;

            if ($line === null) {
                continue;
            }

            if ($existing !== null && (float) ($existing['line'] ?? 0.0) !== $line) {
                continue;
            }

            if ($existing === null) {
                $byPlayer[$playerId][$market] = [
                    'bookmaker' => (string) $row->bookmaker,
                    'market_key' => (string) $row->market_key,
                    'line' => $line,
                    'over_price' => null,
                    'under_price' => null,
                    'over_implied_probability' => null,
                    'under_implied_probability' => null,
                    'fetched_at' => $row->fetched_at?->toIso8601String(),
                    'odds_api_sport_key' => (string) $row->odds_api_sport_key,
                    'player_name' => (string) ($row->outcome_description ?: $row->outcome_name),
                ];
            }

            $byPlayer[$playerId][$market]["{$side}_price"] = $row->price !== null ? (int) $row->price : null;
            $byPlayer[$playerId][$market]["{$side}_implied_probability"] = $row->implied_probability !== null
                ? (float) $row->implied_probability
                : null;
        }

        return $byPlayer;
    }

    protected function normalizeNflPlayerTotalsMarket(string $marketKey): ?string
    {
        return match ($marketKey) {
            'player_pass_yds', 'season_player_pass_yds' => 'passing_yards',
            'player_pass_tds', 'season_player_pass_tds' => 'passing_touchdowns',
            'player_rush_yds', 'season_player_rush_yds' => 'rushing_yards',
            'player_rush_tds', 'season_player_rush_tds' => 'rushing_touchdowns',
            'player_receptions', 'season_player_receptions' => 'receptions',
            'player_reception_yds', 'season_player_reception_yds' => 'receiving_yards',
            'player_reception_tds', 'season_player_reception_tds' => 'receiving_touchdowns',
            default => null,
        };
    }
}
