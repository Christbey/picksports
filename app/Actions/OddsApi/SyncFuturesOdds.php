<?php

namespace App\Actions\OddsApi;

use App\Models\Sports\FuturesOdd;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class SyncFuturesOdds
{
    /**
     * @var array<string, string>
     */
    protected const DEFAULT_FUTURES_SPORT_KEYS = [
        'nba' => 'basketball_nba_championship_winner',
        'mlb' => 'baseball_mlb_world_series_winner',
        'nfl' => 'americanfootball_nfl_super_bowl_winner',
        'cbb' => 'basketball_ncaab_championship_winner',
        'wcbb' => 'basketball_wncaab_championship_winner',
    ];

    /**
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected const TEAM_MODEL_BY_SPORT = [
        'nba' => \App\Models\NBA\Team::class,
        'mlb' => \App\Models\MLB\Team::class,
        'nfl' => \App\Models\NFL\Team::class,
        'cbb' => \App\Models\CBB\Team::class,
        'wcbb' => \App\Models\WCBB\Team::class,
    ];

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

    public function __construct(
        protected OddsApiService $oddsApiService,
        protected SportsViewCache $sportsViewCache
    ) {}

    /**
     * @param  array<int, string>  $sports
     * @param  array<string, string>  $oddsSportOverrides
     * @return array<string, int>
     */
    public function execute(array $sports, ?int $season = null, array $oddsSportOverrides = []): array
    {
        $results = [];
        $fetchedAt = now();

        foreach ($sports as $sport) {
            $sportKey = $oddsSportOverrides[$sport] ?? (self::DEFAULT_FUTURES_SPORT_KEYS[$sport] ?? null);
            if (! $sportKey) {
                $results[$sport] = 0;

                continue;
            }

            $payload = $this->oddsApiService->getFuturesOdds($sportKey);
            if (! is_array($payload) || $payload === []) {
                $results[$sport] = 0;

                continue;
            }

            $rows = $this->buildRows($sport, $season, $sportKey, $payload, $fetchedAt);
            if ($rows === []) {
                $results[$sport] = 0;

                continue;
            }

            $results[$sport] = $this->upsertRows($rows);
        }

        if (array_sum($results) > 0) {
            $this->sportsViewCache->bustSegment(SportsViewCache::SEGMENT_FUTURES_FORECASTS);
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function buildRows(string $sport, ?int $season, string $sportKey, array $payload, CarbonInterface $fetchedAt): array
    {
        $rows = [];

        foreach ($payload as $event) {
            $eventId = (string) ($event['id'] ?? '');
            $eventName = (string) ($event['sport_title'] ?? ($event['name'] ?? $eventId));
            $commenceTime = $this->asCarbonOrNull($event['commence_time'] ?? null);
            $bookmakers = $event['bookmakers'] ?? [];

            if (! is_array($bookmakers)) {
                continue;
            }

            foreach ($bookmakers as $bookmaker) {
                $bookmakerKey = (string) ($bookmaker['key'] ?? 'unknown');
                $markets = $bookmaker['markets'] ?? [];
                if (! is_array($markets)) {
                    continue;
                }

                foreach ($markets as $market) {
                    $marketKey = (string) ($market['key'] ?? '');
                    if ($marketKey === '') {
                        continue;
                    }

                    $marketLastUpdate = $this->asCarbonOrNull($market['last_update'] ?? null);
                    $outcomes = $market['outcomes'] ?? [];
                    if (! is_array($outcomes)) {
                        continue;
                    }

                    foreach ($outcomes as $outcome) {
                        $outcomeName = (string) ($outcome['name'] ?? '');
                        if ($outcomeName === '') {
                            continue;
                        }

                        $price = isset($outcome['price']) && is_numeric($outcome['price'])
                            ? (int) $outcome['price']
                            : null;

                        $outcomeDescription = isset($outcome['description'])
                            ? (string) $outcome['description']
                            : null;

                        $outcomePoint = isset($outcome['point']) && is_numeric($outcome['point'])
                            ? (float) $outcome['point']
                            : null;

                        $teamId = $this->resolveTeamId($sport, $sportKey, $outcomeName);
                        $teamColumns = [
                            'nba_team_id' => null,
                            'mlb_team_id' => null,
                            'nfl_team_id' => null,
                            'cbb_team_id' => null,
                            'wcbb_team_id' => null,
                        ];

                        $teamForeignKey = self::TEAM_FOREIGN_KEY_BY_SPORT[$sport] ?? null;
                        if ($teamForeignKey !== null) {
                            $teamColumns[$teamForeignKey] = $teamId;
                        }

                        $rows[] = [
                            'row_key' => sha1(implode('|', [
                                $sport,
                                (string) $season,
                                $sportKey,
                                $eventId,
                                $bookmakerKey,
                                $marketKey,
                                $outcomeName,
                                (string) $outcomeDescription,
                            ])),
                            'sport' => $sport,
                            'season' => $season,
                            'odds_api_sport_key' => $sportKey,
                            'event_id' => $eventId !== '' ? $eventId : null,
                            'event_name' => $eventName !== '' ? $eventName : null,
                            'commence_time' => $commenceTime,
                            'bookmaker' => $bookmakerKey,
                            'market_key' => $marketKey,
                            'market_last_update' => $marketLastUpdate,
                            'outcome_name' => $outcomeName,
                            'outcome_description' => $outcomeDescription,
                            'outcome_point' => $outcomePoint,
                            'price' => $price,
                            'implied_probability' => $this->toImpliedProbability($price),
                            'raw_data' => json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            'fetched_at' => $fetchedAt,
                            'created_at' => $fetchedAt,
                            'updated_at' => $fetchedAt,
                        ] + $teamColumns;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertRows(array $rows): int
    {
        $updateColumns = [
            'sport',
            'season',
            'odds_api_sport_key',
            'event_id',
            'event_name',
            'commence_time',
            'nba_team_id',
            'mlb_team_id',
            'nfl_team_id',
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
            'fetched_at',
            'updated_at',
        ];

        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            FuturesOdd::query()->upsert($chunk, ['row_key'], $updateColumns);
        }

        return count($rows);
    }

    protected function resolveTeamId(string $sport, string $oddsSportKey, string $outcomeName): ?int
    {
        $teamModelClass = self::TEAM_MODEL_BY_SPORT[$sport] ?? null;
        if ($teamModelClass === null) {
            return null;
        }

        $mappingSportKey = $this->teamMappingSportKeyFromFuturesSportKey($oddsSportKey);
        $mappedEspnName = $mappingSportKey
            ? $this->oddsApiService->mappedEspnTeamName($mappingSportKey, $outcomeName)
            : null;

        $candidateNames = array_values(array_filter([
            $mappedEspnName,
            $outcomeName,
        ]));

        foreach ($candidateNames as $candidateName) {
            $exactMatch = $this->findTeamByName($teamModelClass, $candidateName);
            if ($exactMatch !== null) {
                return (int) $exactMatch->id;
            }
        }

        foreach ($candidateNames as $candidateName) {
            $fuzzyMatch = $this->findTeamByFuzzyName($teamModelClass, $candidateName);
            if ($fuzzyMatch !== null) {
                return (int) $fuzzyMatch->id;
            }
        }

        return null;
    }

    protected function teamMappingSportKeyFromFuturesSportKey(string $oddsSportKey): ?string
    {
        return match (true) {
            str_contains($oddsSportKey, 'basketball_nba') => 'basketball_nba',
            str_contains($oddsSportKey, 'baseball_mlb') => 'baseball_mlb',
            str_contains($oddsSportKey, 'americanfootball_nfl') => 'americanfootball_nfl',
            str_contains($oddsSportKey, 'basketball_ncaab') => 'basketball_ncaab',
            str_contains($oddsSportKey, 'basketball_wncaab') => 'basketball_wncaab',
            default => null,
        };
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $teamModelClass
     */
    protected function findTeamByName(string $teamModelClass, string $candidateName): ?\Illuminate\Database\Eloquent\Model
    {
        $normalized = mb_strtolower(trim($candidateName));

        if ($normalized === '') {
            return null;
        }

        return $teamModelClass::query()
            ->get()
            ->first(function ($team) use ($normalized) {
                $variants = $this->teamNameVariants($team);
                foreach ($variants as $variant) {
                    if ($variant === $normalized) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $teamModelClass
     */
    protected function findTeamByFuzzyName(string $teamModelClass, string $candidateName): ?\Illuminate\Database\Eloquent\Model
    {
        $normalizedTarget = $this->oddsApiService->normalizeTeamName($candidateName);
        if ($normalizedTarget === '') {
            return null;
        }

        $best = $teamModelClass::query()
            ->get()
            ->map(function ($team) use ($normalizedTarget) {
                $variants = $this->teamNameVariants($team);

                $score = 0.0;
                foreach ($variants as $variant) {
                    similar_text($normalizedTarget, $variant, $percent);
                    $score = max($score, $percent);
                }

                return [
                    'team' => $team,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->first();

        if (! $best || $best['score'] < 86.0) {
            return null;
        }

        return $best['team'];
    }

    /**
     * @return array<int, string>
     */
    protected function teamNameVariants(\Illuminate\Database\Eloquent\Model $team): array
    {
        $location = (string) ($team->location ?? '');
        $name = (string) ($team->name ?? '');
        $school = (string) ($team->school ?? '');
        $mascot = (string) ($team->mascot ?? '');
        $displayName = (string) ($team->display_name ?? '');
        $abbreviation = (string) ($team->abbreviation ?? '');

        $variants = [
            $location,
            $name,
            trim($location.' '.$name),
            $school,
            $mascot,
            trim($school.' '.$mascot),
            $displayName,
            $abbreviation,
        ];

        return array_values(array_filter(array_map(
            fn ($variant) => $this->oddsApiService->normalizeTeamName((string) $variant),
            $variants
        )));
    }

    protected function asCarbonOrNull(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function toImpliedProbability(?int $americanOdds): ?float
    {
        if ($americanOdds === null || $americanOdds === 0) {
            return null;
        }

        if ($americanOdds > 0) {
            return 100.0 / ($americanOdds + 100.0);
        }

        $absOdds = abs($americanOdds);

        return $absOdds / ($absOdds + 100.0);
    }
}
