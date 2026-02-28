<?php

namespace App\DataTransferObjects\ESPN;

use App\DataTransferObjects\ESPN\Concerns\ParsesEspnGameFields;

class GameData
{
    public const FINAL_STATUSES = ['STATUS_FINAL', 'STATUS_FULL_TIME'];

    public const IN_PROGRESS_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

    use ParsesEspnGameFields;

    public function __construct(
        public string $espnEventId,
        public int $season,
        public int $week,
        public int $seasonType,
        public string $gameDate,
        public ?string $name,
        public ?string $shortName,
        public string $homeTeamEspnId,
        public string $awayTeamEspnId,
        public ?int $homeScore,
        public ?int $awayScore,
        public ?array $homeLinescores,
        public ?array $awayLinescores,
        public string $status,
        public ?int $period,
        public ?string $gameClock,
        public bool $neutralSite,
        public bool $conferenceGame,
        public ?string $venueName,
        public ?string $venueCity,
        public ?string $venueState,
        public ?int $attendance,
        public ?array $broadcastNetworks,
    ) {}

    public static function fromEspnResponse(array $game): self
    {
        $competition = self::competitionFromGame($game);
        $homeTeam = self::homeCompetitor($competition);
        $awayTeam = self::awayCompetitor($competition);

        $seasonType = (int) ($game['season']['type'] ?? 2);

        return new self(
            espnEventId: (string) $game['id'],
            season: (int) $game['season']['year'],
            week: (int) ($game['week']['number'] ?? 1),
            seasonType: $seasonType,
            gameDate: $game['date'],
            name: $game['name'] ?? null,
            shortName: $game['shortName'] ?? null,
            homeTeamEspnId: self::competitorTeamEspnId($homeTeam),
            awayTeamEspnId: self::competitorTeamEspnId($awayTeam),
            homeScore: self::competitorScore($homeTeam),
            awayScore: self::competitorScore($awayTeam),
            homeLinescores: $homeTeam['linescores'] ?? null,
            awayLinescores: $awayTeam['linescores'] ?? null,
            status: self::normalizeStatus($game['status']['type']['name'] ?? 'scheduled'),
            period: self::intOrNull($game['status']['period'] ?? null),
            gameClock: $game['status']['displayClock'] ?? null,
            neutralSite: $competition['neutralSite'] ?? false,
            conferenceGame: $competition['conferenceCompetition'] ?? false,
            venueName: self::venueName($competition),
            venueCity: self::venueCity($competition),
            venueState: self::venueState($competition),
            attendance: self::intOrNull($competition['attendance'] ?? null),
            broadcastNetworks: self::broadcastNetworks($competition),
        );
    }

    public function toArray(): array
    {
        $dateParts = self::extractDateParts($this->gameDate);

        return [
            'espn_event_id' => $this->espnEventId,
            'season' => $this->season,
            'week' => $this->week,
            'season_type' => $this->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $this->name,
            'short_name' => $this->shortName,
            'status' => $this->status,
            'period' => $this->period,
            'game_clock' => $this->gameClock,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
            'home_linescores' => $this->homeLinescores,
            'away_linescores' => $this->awayLinescores,
            'neutral_site' => $this->neutralSite,
            'conference_game' => $this->conferenceGame,
            'venue_name' => $this->venueName,
            'venue_city' => $this->venueCity,
            'venue_state' => $this->venueState,
            'broadcast_networks' => $this->broadcastNetworks,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function finalStatuses(): array
    {
        return self::FINAL_STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function inProgressStatuses(): array
    {
        return self::IN_PROGRESS_STATUSES;
    }
}
