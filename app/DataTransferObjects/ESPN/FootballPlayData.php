<?php

namespace App\DataTransferObjects\ESPN;

class FootballPlayData
{
    public function __construct(
        public ?string $espnPlayId,
        public int $sequenceNumber,
        public int $period,
        public string $clock,
        public ?string $playType,
        public string $playText,
        public ?int $down,
        public ?int $distance,
        public ?int $yardsToEndzone,
        public ?int $yardsGained,
        public bool $isScoringPlay,
        public bool $isTurnover,
        public bool $isPenalty,
        public int $homeScore,
        public int $awayScore,
        public ?string $possessionTeamEspnId,
    ) {}

    public static function fromEspnResponse(array $play, int $index): self
    {
        return new self(
            espnPlayId: isset($play['id']) ? (string) $play['id'] : null,
            sequenceNumber: $index + 1,
            period: (int) $play['period']['number'],
            clock: $play['clock']['displayValue'] ?? '0:00',
            playType: $play['type']['text'] ?? null,
            playText: $play['text'] ?? '',
            down: isset($play['start']['down']) ? (int) $play['start']['down'] : null,
            distance: isset($play['start']['distance']) ? (int) $play['start']['distance'] : null,
            yardsToEndzone: isset($play['start']['yardsToEndzone']) ? (int) $play['start']['yardsToEndzone'] : null,
            yardsGained: isset($play['statYardage']) ? (int) $play['statYardage'] : null,
            isScoringPlay: $play['scoringPlay'] ?? false,
            isTurnover: isset($play['type']['id']) && in_array($play['type']['id'], ['26', '27', '28', '36']),
            isPenalty: isset($play['type']['id']) && $play['type']['id'] === '24',
            homeScore: (int) ($play['homeScore'] ?? 0),
            awayScore: (int) ($play['awayScore'] ?? 0),
            possessionTeamEspnId: isset($play['start']['team']['id']) ? (string) $play['start']['team']['id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'espn_play_id' => $this->espnPlayId,
            'sequence_number' => $this->sequenceNumber,
            'period' => $this->period,
            'clock' => $this->clock,
            'play_type' => $this->playType,
            'play_text' => $this->playText,
            'down' => $this->down,
            'distance' => $this->distance,
            'yards_to_endzone' => $this->yardsToEndzone,
            'yards_gained' => $this->yardsGained,
            'is_scoring_play' => $this->isScoringPlay,
            'is_turnover' => $this->isTurnover,
            'is_penalty' => $this->isPenalty,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
        ];
    }
}
