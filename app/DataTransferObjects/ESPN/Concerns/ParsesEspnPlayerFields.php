<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait ParsesEspnPlayerFields
{
    use CastsEspnValues;

    /**
     * @param  array<string, mixed>  $player
     * @return array{
     *   espnId:string,
     *   firstName:string,
     *   lastName:string,
     *   fullName:string,
     *   jerseyNumber:?string,
     *   position:?string,
     *   height:?string,
     *   weight:?int,
     *   headshotUrl:?string
     * }
     */
    protected static function parseCommonPlayerFields(array $player): array
    {
        return [
            'espnId' => self::stringOrEmpty($player['id'] ?? null),
            'firstName' => self::stringOrEmpty($player['firstName'] ?? null),
            'lastName' => self::stringOrEmpty($player['lastName'] ?? null),
            'fullName' => self::stringOrEmpty($player['fullName'] ?? $player['displayName'] ?? null),
            'jerseyNumber' => self::stringOrNull($player['jersey'] ?? null),
            'position' => $player['position']['abbreviation'] ?? null,
            'height' => $player['height'] ?? null,
            'weight' => self::intOrNull($player['weight'] ?? null),
            'headshotUrl' => $player['headshot']['href'] ?? null,
        ];
    }
}
