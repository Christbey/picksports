<?php

namespace App\Actions\OddsApi;

abstract class AbstractSportKeySyncPlayerPropsForGames extends AbstractSyncPlayerPropsForGames
{
    protected function fetchEvents(): ?array
    {
        return $this->oddsApiService->getOdds(sport: $this->sportKey());
    }
}
