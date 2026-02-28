<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait ParsesEspnTeamFields
{
    use CastsEspnValues;

    /**
     * @param  array<string, mixed>  $team
     * @return array{
     *   espnId:string,
     *   abbreviation:string,
     *   conference:?string,
     *   division:?string,
     *   color:?string,
     *   logoUrl:?string
     * }
     */
    protected static function parseCommonTeamFields(array $team): array
    {
        return [
            'espnId' => self::stringOrEmpty($team['id'] ?? null),
            'abbreviation' => self::stringOrEmpty($team['abbreviation'] ?? null),
            'conference' => $team['conference']['name'] ?? null,
            'division' => $team['division']['name'] ?? null,
            'color' => $team['color'] ?? null,
            'logoUrl' => $team['logos'][0]['href'] ?? null,
        ];
    }
}
