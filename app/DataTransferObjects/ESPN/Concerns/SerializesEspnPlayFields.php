<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait SerializesEspnPlayFields
{
    /**
     * @return array{
     *   espn_play_id:?string,
     *   sequence_number:int,
     *   play_type:?string,
     *   play_text:string,
     *   home_score:int,
     *   away_score:int
     * }
     */
    protected function serializeCommonPlayFields(): array
    {
        return [
            'espn_play_id' => $this->espnPlayId,
            'sequence_number' => $this->sequenceNumber,
            'play_type' => $this->playType,
            'play_text' => $this->playText,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
        ];
    }

    protected function serializePlayWith(array $fields): array
    {
        return array_merge($this->serializeCommonPlayFields(), $fields);
    }
}
