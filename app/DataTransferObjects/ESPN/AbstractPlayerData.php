<?php

namespace App\DataTransferObjects\ESPN;

use App\DataTransferObjects\ESPN\Concerns\ParsesEspnPlayerFields;
use App\DataTransferObjects\ESPN\Concerns\SerializesEspnPlayerFields;

abstract class AbstractPlayerData
{
    use ParsesEspnPlayerFields;
    use SerializesEspnPlayerFields;

    public static function fromEspnResponse(array $player): static
    {
        $common = self::parseCommonPlayerFields($player);

        return static::fromCommonAndRaw($common, $player);
    }

    public function toArray(): array
    {
        return array_merge(
            $this->serializeCommonPlayerFields(),
            $this->extraPlayerFields()
        );
    }

    /**
     * @param  array{
     *   espnId:string,
     *   firstName:string,
     *   lastName:string,
     *   fullName:string,
     *   jerseyNumber:?string,
     *   position:?string,
     *   height:?string,
     *   weight:?int,
     *   headshotUrl:?string
     * }  $common
     */
    abstract protected static function fromCommonAndRaw(array $common, array $player): static;

    /**
     * @return array<string, mixed>
     */
    abstract protected function extraPlayerFields(): array;
}
