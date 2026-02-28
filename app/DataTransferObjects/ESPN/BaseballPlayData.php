<?php

namespace App\DataTransferObjects\ESPN;

class BaseballPlayData extends AbstractPlayData
{
    public function __construct(
        public ?string $espnPlayId,
        public int $sequenceNumber,
        public int $inning,
        public string $inningHalf,
        public ?string $playType,
        public string $playText,
        public ?int $scoreValue,
        public bool $isAtBat,
        public bool $isScoringPlay,
        public bool $isOut,
        public ?int $balls,
        public ?int $strikes,
        public ?int $outs,
        public int $homeScore,
        public int $awayScore,
        public ?string $battingTeamEspnId,
        public ?string $pitchingTeamEspnId,
    ) {}

    protected static function fromCommonAndRaw(array $common, array $play): static
    {
        $inningData = $play['period'] ?? [];
        $atBatData = $play['atBat'] ?? [];

        return new static(
            espnPlayId: $common['espnPlayId'],
            sequenceNumber: $common['sequenceNumber'],
            inning: self::intOrZero($inningData['number'] ?? 1),
            inningHalf: strtolower(self::stringOrEmpty($inningData['displayValue'] ?? 'bottom')),
            playType: $common['playType'],
            playText: $common['playText'],
            scoreValue: self::intOrNull($play['scoreValue'] ?? null),
            isAtBat: self::boolOrFalse($play['atBat'] ?? null),
            isScoringPlay: self::boolOrFalse($play['scoringPlay'] ?? null),
            isOut: self::playTypeIn($play, ['23', '24', '25', '26', '27', '28']),
            balls: self::intOrNull($atBatData['balls'] ?? null),
            strikes: self::intOrNull($atBatData['strikes'] ?? null),
            outs: self::intOrNull($atBatData['outs'] ?? null),
            homeScore: $common['homeScore'],
            awayScore: $common['awayScore'],
            battingTeamEspnId: self::stringOrNull($play['team']['id'] ?? null),
            pitchingTeamEspnId: self::stringOrNull($play['pitchingTeam']['id'] ?? null),
        );
    }

    protected function extraPlayFields(): array
    {
        return [
            'inning' => $this->inning,
            'inning_half' => $this->inningHalf,
            'score_value' => $this->scoreValue,
            'is_at_bat' => $this->isAtBat,
            'is_scoring_play' => $this->isScoringPlay,
            'is_out' => $this->isOut,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'outs' => $this->outs,
        ];
    }
}
