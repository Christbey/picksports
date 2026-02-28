<?php

namespace App\DataTransferObjects\ESPN;

use App\DataTransferObjects\ESPN\Concerns\ParsesEspnPlayFields;
use App\DataTransferObjects\ESPN\Concerns\SerializesEspnPlayFields;

abstract class AbstractPlayData
{
    use ParsesEspnPlayFields;
    use SerializesEspnPlayFields;

    public static function fromEspnResponse(array $play, int $index): static
    {
        $common = self::parseCommonPlayFields($play, $index);

        return static::fromCommonAndRaw($common, $play);
    }

    public function toArray(): array
    {
        return $this->serializePlayWith($this->extraPlayFields());
    }

    /**
     * @param  array{
     *   espnPlayId:?string,
     *   sequenceNumber:int,
     *   period:int,
     *   clock:string,
     *   playType:?string,
     *   playText:string,
     *   homeScore:int,
     *   awayScore:int
     * }  $common
     */
    abstract protected static function fromCommonAndRaw(array $common, array $play): static;

    /**
     * @return array<string, mixed>
     */
    abstract protected function extraPlayFields(): array;
}
