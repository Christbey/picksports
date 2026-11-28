<?php

namespace App\Actions\OddsApi;

use App\Models\NFL\Game;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\OddsApi\OddsApiService;
use App\Support\Sports\SeasonTypeCandidates;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public const MARKETS_NFL = [
        'player_pass_tds',
        'player_pass_yds',
        'player_pass_completions',
        'player_pass_attempts',
        'player_pass_interceptions',
        'player_rush_yds',
        'player_rush_attempts',
        'player_receptions',
        'player_reception_yds',
        'player_anytime_td',
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

            if ($game instanceof Game && (! PlayerPropAnalyzer::nflPregame($game)
                || $game->playerProps()->whereNotNull('graded_at')->exists())) {
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

            // The provider request may cross kickoff; check again before replacing snapshots.
            if ($game instanceof Game && ! PlayerPropAnalyzer::nflPregame($game->fresh())) {
                continue;
            }

            $stored += DB::transaction(function () use ($game, $propsData, $event, $effectiveSportKey): int {
                $current = $game->newQuery()->lockForUpdate()->findOrFail($game->id);
                if ($current instanceof Game && ! PlayerPropAnalyzer::nflPregame($current)) {
                    return 0;
                }
                $playerPropModel = $this->playerPropModelClass();
                // Keep immutable admission/grade evidence; ordinary mutable quotes
                // still replace as before, and fresh snapshots get fresh row IDs.
                $playerPropModel::query()->where('game_id', $game->id)->whereNull('graded_at')
                    ->whereDoesntHave('gradeRevisions')->delete();
                $count = 0;
                if (isset($propsData['bookmakers']) && is_array($propsData['bookmakers'])) {
                    foreach ($propsData['bookmakers'] as $bookmaker) {
                        $count += $this->storeBookmakerProps($current, $bookmaker, $event['id'], $effectiveSportKey);
                    }
                }

                return $count;
            });
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
                        'line' => $outcome['point'] ?? $this->defaultLineForMarket($marketKey),
                        'over' => null,
                        'under' => null,
                    ];
                }

                $outcomeType = strtolower($outcome['name'] ?? '');
                if (in_array($outcomeType, ['over', 'yes'], true)) {
                    $playerProps[$playerName]['over'] = $outcome['price'] ?? null;
                } elseif (in_array($outcomeType, ['under', 'no'], true)) {
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

    protected function defaultLineForMarket(string $market): ?float
    {
        return match ($market) {
            'player_anytime_td' => 0.5,
            default => null,
        };
    }

    protected function matchEvent(array $event, string $oddsSportKey): ?Model
    {
        $game = $this->matchExistingOddsEvent((string) $event['id'], $oddsSportKey);

        if ($game) {
            return $game;
        }

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

    protected function matchExistingOddsEvent(string $eventId, string $oddsSportKey): ?Model
    {
        if ($eventId === '') {
            return null;
        }

        $gameModel = $this->gameModelClass();
        $query = $gameModel::query()
            ->where('odds_api_event_id', $eventId);

        $seasonType = $this->seasonTypeForOddsSportKey($oddsSportKey);
        if ($seasonType !== null) {
            $query->whereIn('season_type', $this->resolveSeasonTypeCandidates($seasonType));
        }

        return $query
            ->orderByDesc('odds_updated_at')
            ->orderByDesc('game_date')
            ->first();
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
        $normalize = fn (string $value): string => preg_replace('/[^a-z0-9]/', '', strtolower($value));
        $players = $playerModel::query()->whereIn('team_id', $teamIds)->orderBy('id')->get();
        // A reviewed explicit ID is authoritative only within this game's teams.
        // Every name-based path requires one normalized exact full-name match.
        $name = $normalize($mappedEspnName ?: $playerName);
        if ($name !== '') {
            $matches = $players->filter(function (Model $player) use ($normalize, $name): bool {
                $fullName = $player->full_name ?? trim(($player->first_name ?? '').' '.($player->last_name ?? ''));

                return $normalize((string) $fullName) === $name;
            });
            if ($matches->count() === 1) {
                return (int) $matches->first()->id;
            }
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
        return SeasonTypeCandidates::resolve($this->sportKey(), $seasonType);
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
