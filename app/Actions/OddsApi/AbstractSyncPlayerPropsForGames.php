<?php

namespace App\Actions\OddsApi;

use App\Models\PlayerProp;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncPlayerPropsForGames
{
    protected const SPORT_KEY = '';

    protected const GAME_MODEL_CLASS = Model::class;

    protected const DEFAULT_MARKETS = [];

    protected const MATCH_THRESHOLD = 80.0;

    public const MARKETS_BASKETBALL = ['player_points', 'player_rebounds', 'player_assists', 'player_threes'];

    public const MARKETS_STANDARD = ['player_points', 'player_rebounds', 'player_assists'];

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?array $markets = null): int
    {
        $events = $this->fetchEvents();

        if (! $events) {
            return 0;
        }

        $stored = 0;

        foreach ($events as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $game = $this->matchEvent($event);

            if (! $game) {
                continue;
            }

            $propsData = $this->oddsApiService->getPlayerProps(
                eventId: $event['id'],
                sport: $this->sportKey(),
                markets: $markets ?? $this->defaultMarkets()
            );

            if (! $propsData) {
                continue;
            }

            PlayerProp::where('gameable_type', get_class($game))
                ->where('gameable_id', $game->id)
                ->delete();

            if (isset($propsData['bookmakers']) && is_array($propsData['bookmakers'])) {
                foreach ($propsData['bookmakers'] as $bookmaker) {
                    $stored += $this->storeBookmakerProps($game, $bookmaker, $event['id']);
                }
            }
        }

        return $stored;
    }

    protected function storeBookmakerProps(Model $game, array $bookmaker, string $eventId): int
    {
        $stored = 0;

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
                PlayerProp::create([
                    'gameable_type' => get_class($game),
                    'gameable_id' => $game->id,
                    'player_id' => $this->resolvePlayerId($playerName, $game),
                    'sport' => $this->sportKey(),
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

    protected function matchEvent(array $event): ?Model
    {
        $gameDate = date('Y-m-d', strtotime($event['commence_time']));
        $gameModel = $this->gameModelClass();

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', $gameDate)
            ->get();

        foreach ($games as $game) {
            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $this->homeTeamNames($game),
                $this->awayTeamNames($game),
                $this->matchThreshold(),
                $this->sportKey()
            )) {
                return $game;
            }
        }

        return null;
    }

    protected function resolvePlayerId(string $playerName, Model $game): ?int
    {
        return null;
    }

    protected function matchThreshold(): float
    {
        return static::MATCH_THRESHOLD;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    abstract protected function fetchEvents(): ?array;

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
