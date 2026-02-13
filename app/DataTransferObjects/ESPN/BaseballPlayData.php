<?php

namespace App\DataTransferObjects\ESPN;

class BaseballPlayData
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

    public static function fromEspnResponse(array $play, int $index): self
    {
        $inningData = $play['period'] ?? [];
        $atBatData = $play['atBat'] ?? [];

        return new self(
            espnPlayId: isset($play['id']) ? (string) $play['id'] : null,
            sequenceNumber: $index + 1,
            inning: (int) ($inningData['number'] ?? 1),
            inningHalf: strtolower($inningData['displayValue'] ?? 'bottom'),
            playType: $play['type']['text'] ?? null,
            playText: $play['text'] ?? '',
            scoreValue: isset($play['scoreValue']) ? (int) $play['scoreValue'] : null,
            isAtBat: $play['atBat'] ?? false,
            isScoringPlay: $play['scoringPlay'] ?? false,
            isOut: isset($play['type']['id']) && in_array($play['type']['id'], ['23', '24', '25', '26', '27', '28']),
            balls: isset($atBatData['balls']) ? (int) $atBatData['balls'] : null,
            strikes: isset($atBatData['strikes']) ? (int) $atBatData['strikes'] : null,
            outs: isset($atBatData['outs']) ? (int) $atBatData['outs'] : null,
            homeScore: (int) ($play['homeScore'] ?? 0),
            awayScore: (int) ($play['awayScore'] ?? 0),
            battingTeamEspnId: isset($play['team']['id']) ? (string) $play['team']['id'] : null,
            pitchingTeamEspnId: isset($play['pitchingTeam']['id']) ? (string) $play['pitchingTeam']['id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'espn_play_id' => $this->espnPlayId,
            'sequence_number' => $this->sequenceNumber,
            'inning' => $this->inning,
            'inning_half' => $this->inningHalf,
            'play_type' => $this->playType,
            'play_text' => $this->playText,
            'score_value' => $this->scoreValue,
            'is_at_bat' => $this->isAtBat,
            'is_scoring_play' => $this->isScoringPlay,
            'is_out' => $this->isOut,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'outs' => $this->outs,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
        ];
    }
}
