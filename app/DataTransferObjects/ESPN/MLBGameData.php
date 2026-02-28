<?php

namespace App\DataTransferObjects\ESPN;

use App\DataTransferObjects\ESPN\Concerns\ParsesEspnGameFields;

class MLBGameData
{
    use ParsesEspnGameFields;

    public function __construct(
        public string $espnEventId,
        public int $season,
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
        public ?int $inning,
        public ?string $inningState,
        public ?string $venueName,
        public ?string $venueCity,
        public ?string $venueState,
        public ?array $broadcastNetworks,
    ) {}

    public static function fromEspnResponse(array $game): self
    {
        $competition = self::competitionFromGame($game);
        $homeTeam = self::homeCompetitor($competition);
        $awayTeam = self::awayCompetitor($competition);
        $statusType = $game['status']['type']['name'] ?? 'scheduled';

        return new self(
            espnEventId: (string) $game['id'],
            season: (int) ($game['season']['year'] ?? date('Y')),
            seasonType: (int) ($game['season']['type'] ?? 2),
            gameDate: (string) ($game['date'] ?? now()->toIso8601String()),
            name: $game['name'] ?? null,
            shortName: $game['shortName'] ?? null,
            homeTeamEspnId: self::competitorTeamEspnId($homeTeam),
            awayTeamEspnId: self::competitorTeamEspnId($awayTeam),
            homeScore: self::competitorScore($homeTeam),
            awayScore: self::competitorScore($awayTeam),
            homeLinescores: $homeTeam['linescores'] ?? null,
            awayLinescores: $awayTeam['linescores'] ?? null,
            status: self::normalizeStatus($statusType),
            inning: self::intOrNull($game['status']['period'] ?? null),
            inningState: $game['status']['displayClock'] ?? ($game['status']['type']['shortDetail'] ?? null),
            venueName: self::venueName($competition),
            venueCity: self::venueCity($competition),
            venueState: self::venueState($competition),
            broadcastNetworks: self::broadcastNetworks($competition),
        );
    }

    public function toArray(): array
    {
        $dateParts = self::extractDateParts($this->gameDate);

        return [
            'espn_event_id' => $this->espnEventId,
            'season' => $this->season,
            'season_type' => $this->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $this->name,
            'short_name' => $this->shortName,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
            'home_linescores' => $this->homeLinescores,
            'away_linescores' => $this->awayLinescores,
            'status' => $this->status,
            'inning' => $this->inning,
            'inning_state' => $this->inningState,
            'venue_name' => $this->venueName,
            'venue_city' => $this->venueCity,
            'venue_state' => $this->venueState,
            'broadcast_networks' => $this->broadcastNetworks,
        ];
    }
}
