<?php

namespace App\Actions\OddsApi;

abstract class AbstractSportKeySyncPlayerPropsForGames extends AbstractSyncPlayerPropsForGames
{
    protected function fetchEvents(?string $oddsSportKey = null): ?array
    {
        return $this->oddsApiService->getOdds(sport: $this->effectiveSportKey($oddsSportKey));
    }
}
