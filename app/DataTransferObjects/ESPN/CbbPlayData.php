<?php

namespace App\DataTransferObjects\ESPN;

class CbbPlayData extends BasketballPlayData
{
    protected static function fromCommonAndRaw(array $common, array $play): static
    {
        $dto = parent::fromCommonAndRaw($common, $play);
        $reference = $play['team']['$ref'] ?? null;
        if ($dto->possessionTeamEspnId === null && is_string($reference)
            && parse_url($reference, PHP_URL_HOST) === 'sports.core.api.espn.com'
            && preg_match('~/teams/(\d+)$~', (string) parse_url($reference, PHP_URL_PATH), $matches)) {
            $dto->possessionTeamEspnId = $matches[1];
        }

        // The feed's team is the event actor, not necessarily the offense.
        // Only retain events that explicitly identify the offensive team.
        $type = strtolower(preg_replace('/[^a-z0-9]/i', '', $dto->playType ?? ''));
        $dto->isTurnover = str_contains($type, 'turnover');
        if (! $dto->shootingPlay && ! $dto->isTurnover && $type !== 'offensiverebound') {
            $dto->possessionTeamEspnId = null;
        }

        return $dto;
    }
}
