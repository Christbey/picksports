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
        $conference = $team['conference']['name']
            ?? $team['groups']['name']
            ?? $team['group']['name']
            ?? null;

        $division = $team['division']['name']
            ?? $team['groups']['parent']['name']
            ?? $team['group']['parent']['name']
            ?? null;

        return [
            'espnId' => self::stringOrEmpty($team['id'] ?? null),
            'abbreviation' => self::stringOrEmpty($team['abbreviation'] ?? null),
            'conference' => $conference,
            'division' => $division,
            'color' => $team['color'] ?? null,
            'logoUrl' => $team['logos'][0]['href'] ?? null,
        ];
    }
}
