<?php

namespace App\Actions\OddsApi\NFL;

use App\Models\NFL\Game;
use App\Models\PlayerProp;
use App\Services\OddsApi\OddsApiService;

class SyncPlayerPropsForGames
{
    protected string $sport = 'americanfootball_nfl';

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?array $markets = null): int
    {
        $events = $this->oddsApiService->getOdds($this->sport);

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
                sport: $this->sport,
                markets: $markets ?? ['player_points', 'player_rebounds', 'player_assists']
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

    protected function storeBookmakerProps(Game $game, array $bookmaker, string $eventId): int
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
                    'player_id' => null,
                    'sport' => $this->sport,
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

    protected function matchEvent(array $event): ?Game
    {
        $commenceTime = $event['commence_time'];
        $gameDate = date('Y-m-d', strtotime($commenceTime));

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', $gameDate)
            ->get();

        foreach ($games as $game) {
            $homeNames = array_filter([
                trim(($game->homeTeam->location ?? '').' '.($game->homeTeam->name ?? '')),
                $game->homeTeam->location ?? '',
                $game->homeTeam->name ?? '',
                $game->homeTeam->abbreviation ?? '',
            ]);

            $awayNames = array_filter([
                trim(($game->awayTeam->location ?? '').' '.($game->awayTeam->name ?? '')),
                $game->awayTeam->location ?? '',
                $game->awayTeam->name ?? '',
                $game->awayTeam->abbreviation ?? '',
            ]);

            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $homeNames,
                $awayNames,
                80.0,
                $this->sport
            )) {
                return $game;
            }
        }

        return null;
    }
}
