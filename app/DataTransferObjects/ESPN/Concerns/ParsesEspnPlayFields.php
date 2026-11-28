<?php

namespace App\DataTransferObjects\ESPN\Concerns;

use App\Support\ESPN\PlayIdentity;
use App\Support\ScoreObservation;

trait ParsesEspnPlayFields
{
    use CastsEspnValues;

    /**
     * @param  array<string, mixed>  $play
     * @return array{
     *   espnPlayId:?string,
     *   sequenceNumber:int,
     *   period:int,
     *   clock:string,
     *   playType:?string,
     *   playText:string,
     *   homeScore:?int,
     *   awayScore:?int
     * }
     */
    protected static function parseCommonPlayFields(array $play, int $index): array
    {
        return [
            'espnPlayId' => PlayIdentity::from($play['id'] ?? null),
            'sequenceNumber' => $index + 1,
            'period' => self::intOrZero($play['period']['number'] ?? null),
            'clock' => self::stringOrEmpty($play['clock']['displayValue'] ?? '0:00'),
            'playType' => $play['type']['text'] ?? null,
            'playText' => self::stringOrEmpty($play['text'] ?? null),
            'homeScore' => ScoreObservation::from($play['homeScore'] ?? null),
            'awayScore' => ScoreObservation::from($play['awayScore'] ?? null),
        ];
    }

    protected static function playTypeId(array $play): ?string
    {
        return self::stringOrNull($play['type']['id'] ?? null);
    }

    /**
     * @param  array<int, string>  $ids
     */
    protected static function playTypeIn(array $play, array $ids): bool
    {
        $typeId = self::playTypeId($play);

        return $typeId !== null && in_array($typeId, $ids, true);
    }
}
