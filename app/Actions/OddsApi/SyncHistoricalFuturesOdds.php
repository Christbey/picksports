<?php

namespace App\Actions\OddsApi;

use App\Models\Sports\FuturesOddsSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class SyncHistoricalFuturesOdds extends SyncFuturesOdds
{
    /**
     * @param  array<int, string>  $sports
     * @param  array<int, string>  $dates
     * @param  array<int, string>  $markets
     * @param  array<string, string>  $oddsSportOverrides
     * @return array<string, int>
     */
    public function executeHistorical(
        array $sports,
        array $dates,
        ?int $season = null,
        array $oddsSportOverrides = [],
        array $markets = ['outrights'],
        string $bookmaker = 'draftkings'
    ): array {
        $results = [];
        $resolvedMarkets = array_values(array_unique(array_filter(array_map(
            static fn ($market) => trim((string) $market),
            $markets
        ))));

        if ($resolvedMarkets === []) {
            $resolvedMarkets = ['outrights'];
        }

        foreach ($sports as $sport) {
            $results[$sport] = 0;
            $sportKey = $oddsSportOverrides[$sport] ?? (self::DEFAULT_FUTURES_SPORT_KEYS[$sport] ?? null);
            if (! $sportKey) {
                continue;
            }

            foreach ($dates as $date) {
                $response = $this->oddsApiService->getHistoricalOdds(
                    sport: $sportKey,
                    date: Carbon::parse($date)->utc()->format('Y-m-d\TH:i:s\Z'),
                    eventId: null,
                    bookmaker: $bookmaker,
                    markets: implode(',', $resolvedMarkets)
                );

                if (! is_array($response)) {
                    continue;
                }

                $payload = $response['data'] ?? $response;
                if (! is_array($payload) || $payload === []) {
                    continue;
                }

                $capturedAt = isset($response['timestamp']) && is_string($response['timestamp'])
                    ? Carbon::parse($response['timestamp'])
                    : Carbon::parse($date);

                $rows = $this->buildRows($sport, $season, $sportKey, $payload, $capturedAt);
                if ($rows === []) {
                    continue;
                }

                $results[$sport] += $this->insertSnapshots($rows, $capturedAt);
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function insertSnapshots(array $rows, CarbonInterface $capturedAt): int
    {
        $payload = array_map(function (array $row) use ($capturedAt): array {
            return [
                'snapshot_key' => sha1($row['row_key'].'|'.$capturedAt->toIso8601String()),
                'row_key' => $row['row_key'],
                'sport' => $row['sport'],
                'season' => $row['season'],
                'odds_api_sport_key' => $row['odds_api_sport_key'],
                'event_id' => $row['event_id'],
                'event_name' => $row['event_name'],
                'commence_time' => $row['commence_time'],
                'nba_team_id' => $row['nba_team_id'] ?? null,
                'mlb_team_id' => $row['mlb_team_id'] ?? null,
                'nfl_team_id' => $row['nfl_team_id'] ?? null,
                'nfl_player_id' => $row['nfl_player_id'] ?? null,
                'cbb_team_id' => $row['cbb_team_id'] ?? null,
                'wcbb_team_id' => $row['wcbb_team_id'] ?? null,
                'bookmaker' => $row['bookmaker'],
                'market_key' => $row['market_key'],
                'market_last_update' => $row['market_last_update'],
                'outcome_name' => $row['outcome_name'],
                'outcome_description' => $row['outcome_description'],
                'outcome_point' => $row['outcome_point'],
                'price' => $row['price'],
                'implied_probability' => $row['implied_probability'],
                'raw_data' => $row['raw_data'],
                'captured_at' => $capturedAt,
                'created_at' => $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }, $rows);

        $updateColumns = [
            'row_key',
            'sport',
            'season',
            'odds_api_sport_key',
            'event_id',
            'event_name',
            'commence_time',
            'nba_team_id',
            'mlb_team_id',
            'nfl_team_id',
            'nfl_player_id',
            'cbb_team_id',
            'wcbb_team_id',
            'bookmaker',
            'market_key',
            'market_last_update',
            'outcome_name',
            'outcome_description',
            'outcome_point',
            'price',
            'implied_probability',
            'raw_data',
            'captured_at',
            'updated_at',
        ];

        foreach (array_chunk($payload, 500) as $chunk) {
            FuturesOddsSnapshot::query()->upsert($chunk, ['snapshot_key'], $updateColumns);
        }

        return count($payload);
    }
}
