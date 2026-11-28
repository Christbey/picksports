<?php

namespace App\Services\WNBA;

use App\Models\WNBA\Team;
use Illuminate\Database\Connection;
use RuntimeException;

/** Cooperative league gate: all supported WNBA Elo writers lock this before games/history. */
class WnbaEloPublicationGate
{
    public function lock(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('WNBA Elo publication requires a transaction.');
        }
        if (Team::on($connection->getName())->orderBy('id')->lockForUpdate()->first(['id']) === null) {
            throw new RuntimeException('WNBA Elo publication requires a known league team anchor.');
        }
    }
}
