<?php

namespace App\DataTransferObjects\ESPN;

class BasketballPlayData
{
    public function __construct(
        public ?string $espnPlayId,
        public int $sequenceNumber,
        public int $period,
        public string $clock,
        public ?string $playType,
        public string $playText,
        public ?int $scoreValue,
        public bool $shootingPlay,
        public bool $madeShot,
        public bool $assist,
        public bool $isTurnover,
        public bool $isFoul,
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
            scoreValue: isset($play['scoreValue']) ? (int) $play['scoreValue'] : null,
            shootingPlay: $play['shootingPlay'] ?? false,
            madeShot: isset($play['scoringPlay']) ? (bool) $play['scoringPlay'] : false,
            assist: isset($play['type']['id']) && $play['type']['id'] === '1',
            isTurnover: isset($play['type']['id']) && in_array($play['type']['id'], ['10', '11']),
            isFoul: isset($play['type']['id']) && $play['type']['id'] === '6',
            homeScore: (int) ($play['homeScore'] ?? 0),
            awayScore: (int) ($play['awayScore'] ?? 0),
            possessionTeamEspnId: isset($play['team']['id']) ? (string) $play['team']['id'] : null,
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
            'score_value' => $this->scoreValue,
            'shooting_play' => $this->shootingPlay,
            'made_shot' => $this->madeShot,
            'assist' => $this->assist,
            'is_turnover' => $this->isTurnover,
            'is_foul' => $this->isFoul,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
        ];
    }
}
