<?php

namespace App\DataTransferObjects\ESPN;

class BasketballPlayData extends AbstractPlayData
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

    protected static function fromCommonAndRaw(array $common, array $play): static
    {
        return new static(
            espnPlayId: $common['espnPlayId'],
            sequenceNumber: $common['sequenceNumber'],
            period: $common['period'],
            clock: $common['clock'],
            playType: $common['playType'],
            playText: $common['playText'],
            scoreValue: self::intOrNull($play['scoreValue'] ?? null),
            shootingPlay: self::boolOrFalse($play['shootingPlay'] ?? null),
            madeShot: self::boolOrFalse($play['scoringPlay'] ?? null),
            assist: self::playTypeIn($play, ['1']),
            isTurnover: self::playTypeIn($play, ['10', '11']),
            isFoul: self::playTypeIn($play, ['6']),
            homeScore: $common['homeScore'],
            awayScore: $common['awayScore'],
            possessionTeamEspnId: self::stringOrNull($play['team']['id'] ?? null),
        );
    }

    protected function extraPlayFields(): array
    {
        return [
            'period' => $this->period,
            'clock' => $this->clock,
            'score_value' => $this->scoreValue,
            'shooting_play' => $this->shootingPlay,
            'made_shot' => $this->madeShot,
            'assist' => $this->assist,
            'is_turnover' => $this->isTurnover,
            'is_foul' => $this->isFoul,
        ];
    }
}
