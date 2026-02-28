<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait SerializesEspnTeamFields
{
    /**
     * @return array{
     *   espn_id:string,
     *   abbreviation:string,
     *   conference:?string,
     *   division:?string,
     *   color:?string,
     *   logo_url:?string
     * }
     */
    protected function serializeCommonTeamFields(): array
    {
        return [
            'espn_id' => $this->espnId,
            'abbreviation' => $this->abbreviation,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'logo_url' => $this->logoUrl,
        ];
    }
}
