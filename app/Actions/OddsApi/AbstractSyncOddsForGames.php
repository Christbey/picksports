<?php

namespace App\Actions\OddsApi;

use App\Services\OddsApi\OddsApiService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncOddsForGames
{
    protected const SPORT_KEY = '';

    protected const GAME_MODEL_CLASS = Model::class;

    protected const MATCH_THRESHOLD = 80.0;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = false;

    protected const INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES = false;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = true;

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->fetchOddsData();

        if (! $oddsData) {
            return 0;
        }

        $updated = 0;

        foreach ($oddsData as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $game = $this->matchEvent($event);

            if (! $game) {
                continue;
            }

            $game->update([
                'odds_api_event_id' => $event['id'],
                'odds_data' => $this->oddsApiService->extractOddsData($event),
                'odds_updated_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
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

    protected function matchThreshold(): float
    {
        return static::MATCH_THRESHOLD;
    }

    /**
     * @return array<string,mixed>|array<int,array<string,mixed>>|null
     */
    protected function fetchOddsData(): ?array
    {
        return $this->oddsApiService->getOdds(null, $this->sportKey());
    }

    protected function sportKey(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on odds sync action.');
        }

        return static::SPORT_KEY;
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined on odds sync action.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @return array<int,string>
     */
    protected function homeTeamNames(Model $game): array
    {
        return $this->locationNameDisplayTeamNames(
            $game->homeTeam,
            includeAbbreviation: $this->includeAbbreviationInTeamNames(),
            includeLocationAndName: $this->includeLocationAndNameInTeamNames(),
            includeDisplayName: $this->includeDisplayNameInTeamNames()
        );
    }

    /**
     * @return array<int,string>
     */
    protected function awayTeamNames(Model $game): array
    {
        return $this->locationNameDisplayTeamNames(
            $game->awayTeam,
            includeAbbreviation: $this->includeAbbreviationInTeamNames(),
            includeLocationAndName: $this->includeLocationAndNameInTeamNames(),
            includeDisplayName: $this->includeDisplayNameInTeamNames()
        );
    }

    protected function includeAbbreviationInTeamNames(): bool
    {
        return static::INCLUDE_ABBREVIATION_IN_TEAM_NAMES;
    }

    protected function includeLocationAndNameInTeamNames(): bool
    {
        return static::INCLUDE_LOCATION_AND_NAME_IN_TEAM_NAMES;
    }

    protected function includeDisplayNameInTeamNames(): bool
    {
        return static::INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES;
    }

    /**
     * @return array<int,string>
     */
    protected function locationNameDisplayTeamNames(
        mixed $team,
        bool $includeAbbreviation = false,
        bool $includeLocationAndName = false,
        bool $includeDisplayName = true
    ): array {
        $names = [
            $team->location ?? '',
            $team->name ?? '',
        ];

        if ($includeDisplayName) {
            $names[] = $team->display_name ?? '';
        }

        if ($includeAbbreviation) {
            $names[] = $team->abbreviation ?? '';
        }

        if ($includeLocationAndName) {
            $names[] = trim(($team->location ?? '').' '.($team->name ?? ''));
        }

        return array_filter($names);
    }

    /**
     * @return array<int,string>
     */
    protected function schoolMascotAbbreviationTeamNames(mixed $team): array
    {
        return array_filter([
            $team->school ?? '',
            $team->mascot ?? '',
            $team->abbreviation ?? '',
        ]);
    }
}
