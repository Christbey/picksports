<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait SerializesEspnPlayerFields
{
    /**
     * @return array{
     *   espn_id:string,
     *   first_name:string,
     *   last_name:string,
     *   full_name:string,
     *   jersey_number:?string,
     *   position:?string,
     *   height:?string,
     *   weight:?int,
     *   headshot_url:?string
     * }
     */
    protected function serializeCommonPlayerFields(): array
    {
        return [
            'espn_id' => $this->espnId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'jersey_number' => $this->jerseyNumber,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'headshot_url' => $this->headshotUrl,
        ];
    }
}
