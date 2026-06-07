<?php

namespace App\Actions\OddsApi;

use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class AbstractSyncPlayerPropsForGames
{
    protected const SPORT_KEY = '';

    protected const GAME_MODEL_CLASS = Model::class;

    protected const PLAYER_PROP_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const DEFAULT_MARKETS = [];

    protected const MATCH_THRESHOLD = 80.0;

    public const MARKETS_BASKETBALL = ['player_points', 'player_rebounds', 'player_assists', 'player_threes'];

    public const MARKETS_MLB = [
        'batter_home_runs',
        'batter_hits',
        'batter_total_bases',
        'batter_rbis',
        'batter_runs_scored',
        'batter_hits_runs_rbis',
        'pitcher_strikeouts',
        'pitcher_hits_allowed',
        'pitcher_walks',
        'pitcher_earned_runs',
    ];

    public const MARKETS_STANDARD = ['player_points', 'player_rebounds', 'player_assists'];

    public function __construct(
        protected OddsApiService $oddsApiService,
        protected SportsViewCache $sportsViewCache
    ) {}

    public function execute(?array $markets = null, ?string $oddsSportKey = null): int
    {
        $effectiveSportKey = $this->effectiveSportKey($oddsSportKey);
        $events = $this->fetchEvents($effectiveSportKey);

        if (! $events) {
            return 0;
        }

        $stored = 0;

        foreach ($events as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $game = $this->matchEvent($event, $effectiveSportKey);

            if (! $game) {
                continue;
            }

            $propsData = $this->oddsApiService->getPlayerProps(
                eventId: $event['id'],
                sport: $effectiveSportKey,
                markets: $markets ?? $this->defaultMarkets()
            );

            if (! $propsData) {
                continue;
            }

            $playerPropModel = $this->playerPropModelClass();
            $playerPropModel::query()
                ->where('game_id', $game->id)
                ->delete();

            if (isset($propsData['bookmakers']) && is_array($propsData['bookmakers'])) {
                foreach ($propsData['bookmakers'] as $bookmaker) {
                    $stored += $this->storeBookmakerProps($game, $bookmaker, $event['id'], $effectiveSportKey);
                }
            }
        }

        if ($stored > 0) {
            $this->sportsViewCache->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
        }

        return $stored;
    }

    protected function storeBookmakerProps(Model $game, array $bookmaker, string $eventId, string $oddsSportKey): int
    {
        $stored = 0;
        $playerPropModel = $this->playerPropModelClass();

        if (! isset($bookmaker['markets']) || ! is_array($bookmaker['markets'])) {
            return 0;
        }

        foreach ($bookmaker['markets'] as $market) {
            $marketKey = $market['key'] ?? null;

            if (! $marketKey || ! isset($market['outcomes']) || ! is_array($market['outcomes'])) {
                continue;
            }

            $playerProps = [];
            foreach ($market['outcomes'] as $outcome) {
                $playerName = $outcome['description'] ?? null;

                if (! $playerName) {
                    continue;
                }

                if (! isset($playerProps[$playerName])) {
                    $playerProps[$playerName] = [
                        'line' => $outcome['point'] ?? null,
                        'over' => null,
                        'under' => null,
                    ];
                }

                $outcomeType = strtolower($outcome['name'] ?? '');
                if ($outcomeType === 'over') {
                    $playerProps[$playerName]['over'] = $outcome['price'] ?? null;
                } elseif ($outcomeType === 'under') {
                    $playerProps[$playerName]['under'] = $outcome['price'] ?? null;
                }
            }

            foreach ($playerProps as $playerName => $propData) {
                $playerPropModel::query()->create([
                    'game_id' => $game->id,
                    'player_id' => $this->resolvePlayerId($playerName, $game, $oddsSportKey),
                    'odds_api_event_id' => $eventId,
                    'player_name' => $playerName,
                    'market' => $marketKey,
                    'bookmaker' => $bookmaker['key'] ?? 'draftkings',
                    'line' => $propData['line'],
                    'over_price' => $propData['over'],
                    'under_price' => $propData['under'],
                    'raw_data' => $propData,
                    'fetched_at' => now(),
                ]);

                $stored++;
            }
        }

        return $stored;
    }

    protected function matchEvent(array $event, string $oddsSportKey): ?Model
    {
        $gameDates = $this->candidateGameDates((string) $event['commence_time']);
        $gameModel = $this->gameModelClass();

        $query = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where(function ($query) use ($gameDates): void {
                foreach ($gameDates as $gameDate) {
                    $query->orWhereDate('game_date', $gameDate);
                }
            });

        $seasonType = $this->seasonTypeForOddsSportKey($oddsSportKey);
        if ($seasonType !== null) {
            $query->whereIn('season_type', $this->resolveSeasonTypeCandidates($seasonType));
        }

        $games = $query->get();

        foreach ($games as $game) {
            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $this->homeTeamNames($game),
                $this->awayTeamNames($game),
                $this->matchThreshold(),
                $oddsSportKey
            )) {
                return $game;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function candidateGameDates(string $commenceTime): array
    {
        $commenceAt = Carbon::parse($commenceTime);

        return array_values(array_unique([
            $commenceAt->copy()->utc()->toDateString(),
            $commenceAt->copy()->setTimezone('America/New_York')->toDateString(),
            $commenceAt->copy()->setTimezone('America/Chicago')->toDateString(),
            $commenceAt->copy()->utc()->subDay()->toDateString(),
            $commenceAt->copy()->utc()->addDay()->toDateString(),
        ]));
    }

    protected function resolvePlayerId(string $playerName, Model $game, string $oddsSportKey): ?int
    {
        $playerModel = $this->playerModelClass();
        $teamIds = array_filter([
            $game->home_team_id ?? null,
            $game->away_team_id ?? null,
        ]);

        $mappedPlayerId = $this->oddsApiService->mappedEspnPlayerId($oddsSportKey, $playerName);
        if ($mappedPlayerId) {
            $mappedPlayer = $playerModel::query()
                ->whereIn('team_id', $teamIds)
                ->whereKey($mappedPlayerId)
                ->first();

            if ($mappedPlayer) {
                return $mappedPlayer->id;
            }
        }

        $mappedEspnName = $this->oddsApiService->mappedEspnPlayerName($oddsSportKey, $playerName);
        if ($mappedEspnName) {
            $mappedPlayer = $playerModel::query()
                ->whereIn('team_id', $teamIds)
                ->where(function ($query) use ($mappedEspnName) {
                    $query->whereRaw('LOWER(full_name) = ?', [mb_strtolower($mappedEspnName)])
                        ->orWhere('full_name', 'like', "%{$mappedEspnName}%");
                })
                ->first();

            if ($mappedPlayer) {
                return $mappedPlayer->id;
            }
        }

        $directMatch = $playerModel::query()
            ->whereIn('team_id', $teamIds)
            ->where(function ($query) use ($playerName) {
                $query->whereRaw('LOWER(full_name) = ?', [mb_strtolower($playerName)])
                    ->orWhere('full_name', 'like', "%{$playerName}%");
            })
            ->first();

        if ($directMatch) {
            return $directMatch->id;
        }

        $normalizedInput = $this->oddsApiService->normalizePlayerName($playerName);
        $lastName = str_contains($normalizedInput, ' ')
            ? (string) str($normalizedInput)->afterLast(' ')
            : $normalizedInput;

        $candidate = $playerModel::query()
            ->whereIn('team_id', $teamIds)
            ->whereNotNull('full_name')
            ->get()
            ->map(function ($player) use ($normalizedInput) {
                $normalizedCandidate = $this->oddsApiService->normalizePlayerName((string) $player->full_name);
                similar_text($normalizedInput, $normalizedCandidate, $score);

                return [
                    'player' => $player,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->first();

        if ($candidate && $candidate['score'] >= 82.0) {
            return $candidate['player']->id;
        }

        if ($candidate) {
            $this->oddsApiService->rememberUnmappedPlayer(
                $oddsSportKey,
                $playerName,
                $candidate['player'],
                (int) round($candidate['score'])
            );
        }

        $lastNameMatch = $playerModel::query()
            ->whereIn('team_id', $teamIds)
            ->whereRaw('LOWER(last_name) = ?', [$lastName])
            ->first();

        if ($lastNameMatch) {
            $this->oddsApiService->rememberUnmappedPlayer($oddsSportKey, $playerName, $lastNameMatch, 75);

            return $lastNameMatch->id;
        }

        $this->oddsApiService->rememberUnmappedPlayer($oddsSportKey, $playerName);

        return null;
    }

    protected function matchThreshold(): float
    {
        return static::MATCH_THRESHOLD;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    abstract protected function fetchEvents(?string $oddsSportKey = null): ?array;

    protected function effectiveSportKey(?string $oddsSportKey): string
    {
        return ($oddsSportKey !== null && $oddsSportKey !== '')
            ? $oddsSportKey
            : $this->sportKey();
    }

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function defaultMarkets(): array
    {
        if (static::DEFAULT_MARKETS === []) {
            throw new \RuntimeException('DEFAULT_MARKETS must be defined on player-props sync action.');
        }

        return static::DEFAULT_MARKETS;
    }

    protected function sportKey(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on player-props sync action.');
        }

        return static::SPORT_KEY;
    }

    /**
     * @return array<int, int|string>
     */
    protected function resolveSeasonTypeCandidates(int|string $seasonType): array
    {
        $sportSlug = $this->sportKey();
        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [$seasonType, (string) $seasonType];

        if (is_string($seasonType) && isset($typeNames[$seasonType])) {
            $candidates[] = $typeNames[$seasonType];
        }

        if (is_string($seasonType) && isset($typesByKey[$seasonType])) {
            $resolved = $typesByKey[$seasonType];
            $candidates[] = $resolved;
            $candidates[] = (string) $resolved;
        }

        if (is_numeric($seasonType)) {
            $code = (int) $seasonType;
            $matchedKey = array_search($code, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined on player-props sync action.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerPropModelClass(): string
    {
        if (static::PLAYER_PROP_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_PROP_MODEL_CLASS must be defined on player-props sync action.');
        }

        return static::PLAYER_PROP_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        if (static::PLAYER_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_MODEL_CLASS must be defined on player-props sync action.');
        }

        return static::PLAYER_MODEL_CLASS;
    }

    /**
     * @return array<int, string>
     */
    protected function homeTeamNames(Model $game): array
    {
        return $this->locationNameAbbreviationTeamNames($game->homeTeam);
    }

    /**
     * @return array<int, string>
     */
    protected function awayTeamNames(Model $game): array
    {
        return $this->locationNameAbbreviationTeamNames($game->awayTeam);
    }

    /**
     * @return array<int, string>
     */
    protected function locationNameAbbreviationTeamNames(mixed $team): array
    {
        return array_filter([
            trim(($team->location ?? '').' '.($team->name ?? '')),
            $team->location ?? '',
            $team->name ?? '',
            $team->abbreviation ?? '',
        ]);
    }
}
