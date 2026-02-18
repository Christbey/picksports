<?php

namespace App\DataTransferObjects\ESPN;

class GameData
{
    public function __construct(
        public string $espnEventId,
        public int $season,
        public int $week,
        public string $seasonType,
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

    /**
     * Normalize ESPN status value to uppercase STATUS_* format
     */
    public static function normalizeStatus(string $status): string
    {
        $trimmedStatus = trim($status);

        // If status is already in STATUS_* format, return it as-is
        if (str_starts_with($trimmedStatus, 'STATUS_')) {
            return $trimmedStatus;
        }

        $statusMap = [
            'scheduled' => 'STATUS_SCHEDULED',
            'pre' => 'STATUS_SCHEDULED',
            'in progress' => 'STATUS_IN_PROGRESS',
            'in_progress' => 'STATUS_IN_PROGRESS',
            'in' => 'STATUS_IN_PROGRESS',
            'final' => 'STATUS_FINAL',
            'post' => 'STATUS_FINAL',
            'postponed' => 'STATUS_POSTPONED',
            'canceled' => 'STATUS_CANCELED',
            'cancelled' => 'STATUS_CANCELED',
            'suspended' => 'STATUS_SUSPENDED',
            'delayed' => 'STATUS_DELAYED',
        ];

        $normalizedKey = strtolower($trimmedStatus);

        return $statusMap[$normalizedKey] ?? 'STATUS_SCHEDULED';
    }

    public static function fromEspnResponse(array $game): self
    {
        $competition = $game['competitions'][0] ?? [];
        $homeTeam = collect($competition['competitors'] ?? [])->firstWhere('homeAway', 'home');
        $awayTeam = collect($competition['competitors'] ?? [])->firstWhere('homeAway', 'away');

        // Map season type number to string
        $seasonTypeMap = [
            1 => 'Preseason',
            2 => 'Regular Season',
            3 => 'Postseason',
        ];
        $seasonTypeNum = (int) ($game['season']['type'] ?? 2);
        $seasonType = $seasonTypeMap[$seasonTypeNum] ?? 'Regular Season';

        return new self(
            espnEventId: (string) $game['id'],
            season: (int) $game['season']['year'],
            week: (int) ($game['week']['number'] ?? 1),
            seasonType: $seasonType,
            gameDate: $game['date'],
            name: $game['name'] ?? null,
            shortName: $game['shortName'] ?? null,
            homeTeamEspnId: (string) $homeTeam['team']['id'],
            awayTeamEspnId: (string) $awayTeam['team']['id'],
            homeScore: isset($homeTeam['score']) && is_numeric($homeTeam['score']) ? (int) $homeTeam['score'] : null,
            awayScore: isset($awayTeam['score']) && is_numeric($awayTeam['score']) ? (int) $awayTeam['score'] : null,
            homeLinescores: $homeTeam['linescores'] ?? null,
            awayLinescores: $awayTeam['linescores'] ?? null,
            status: self::normalizeStatus($game['status']['type']['name'] ?? 'scheduled'),
            period: isset($game['status']['period']) ? (int) $game['status']['period'] : null,
            gameClock: $game['status']['displayClock'] ?? null,
            neutralSite: $competition['neutralSite'] ?? false,
            conferenceGame: $competition['conferenceCompetition'] ?? false,
            venueName: $competition['venue']['fullName'] ?? null,
            venueCity: $competition['venue']['address']['city'] ?? null,
            venueState: $competition['venue']['address']['state'] ?? null,
            attendance: isset($competition['attendance']) ? (int) $competition['attendance'] : null,
            broadcastNetworks: collect($competition['broadcasts'] ?? [])->pluck('names')->flatten()->toArray(),
        );
    }

    public function toArray(): array
    {
        $gameDateTime = new \DateTime($this->gameDate);

        return [
            'espn_event_id' => $this->espnEventId,
            'season' => $this->season,
            'week' => $this->week,
            'season_type' => $this->seasonType,
            'game_date' => $gameDateTime->format('Y-m-d'),
            'game_time' => $gameDateTime->format('H:i:s'),
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
}
