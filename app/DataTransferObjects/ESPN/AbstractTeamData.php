<?php

namespace App\DataTransferObjects\ESPN;

use App\DataTransferObjects\ESPN\Concerns\ParsesEspnTeamFields;
use App\DataTransferObjects\ESPN\Concerns\SerializesEspnTeamFields;

abstract class AbstractTeamData
{
    use ParsesEspnTeamFields;
    use SerializesEspnTeamFields;

    public static function fromEspnResponse(array $team): static
    {
        $common = self::parseCommonTeamFields($team);

        return static::fromCommonAndRaw($common, $team);
    }

    public function toArray(): array
    {
        return array_merge(
            $this->serializeCommonTeamFields(),
            $this->extraTeamFields()
        );
    }

    /**
     * @param  array{
     *   espnId:string,
     *   abbreviation:string,
     *   conference:?string,
     *   division:?string,
     *   color:?string,
     *   logoUrl:?string
     * }  $common
     */
    abstract protected static function fromCommonAndRaw(array $common, array $team): static;

    /**
     * @return array<string, mixed>
     */
    abstract protected function extraTeamFields(): array;
}
