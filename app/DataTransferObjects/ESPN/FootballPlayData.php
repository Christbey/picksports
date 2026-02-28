<?php

namespace App\DataTransferObjects\ESPN;

class FootballPlayData extends AbstractPlayData
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

    protected static function fromCommonAndRaw(array $common, array $play): static
    {
        return new static(
            espnPlayId: $common['espnPlayId'],
            sequenceNumber: $common['sequenceNumber'],
            period: $common['period'],
            clock: $common['clock'],
            playType: $common['playType'],
            playText: $common['playText'],
            down: self::intOrNull($play['start']['down'] ?? null),
            distance: self::intOrNull($play['start']['distance'] ?? null),
            yardsToEndzone: self::intOrNull($play['start']['yardsToEndzone'] ?? null),
            yardsGained: self::intOrNull($play['statYardage'] ?? null),
            isScoringPlay: self::boolOrFalse($play['scoringPlay'] ?? null),
            isTurnover: self::playTypeIn($play, ['26', '27', '28', '36']),
            isPenalty: self::playTypeIn($play, ['24']),
            homeScore: $common['homeScore'],
            awayScore: $common['awayScore'],
            possessionTeamEspnId: self::stringOrNull($play['start']['team']['id'] ?? null),
        );
    }

    protected function extraPlayFields(): array
    {
        return [
            'period' => $this->period,
            'clock' => $this->clock,
            'down' => $this->down,
            'distance' => $this->distance,
            'yards_to_endzone' => $this->yardsToEndzone,
            'yards_gained' => $this->yardsGained,
            'is_scoring_play' => $this->isScoringPlay,
            'is_turnover' => $this->isTurnover,
            'is_penalty' => $this->isPenalty,
        ];
    }
}
