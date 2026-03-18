<?php

namespace App\Support;

class EspnGameStatusResolver
{
    public function resolveForCreate(?string $incomingStatus, string $source, string $sport): ?string
    {
        return $incomingStatus;
    }

    public function resolveForUpdate(?string $currentStatus, ?string $incomingStatus, string $source, string $sport): ?string
    {
        if ($incomingStatus === null || $incomingStatus === '') {
            return $currentStatus;
        }

        if ($currentStatus === null || $currentStatus === '') {
            return $this->resolveForCreate($incomingStatus, $source, $sport);
        }

        $currentRank = $this->rank($currentStatus, $sport);
        $incomingRank = $this->rank($incomingStatus, $sport);

        if ($this->isFinal($currentStatus, $sport)) {
            return $currentStatus;
        }

        if ($source === 'schedule' && $this->isFinal($incomingStatus, $sport)) {
            return $currentStatus;
        }

        if ($incomingRank < $currentRank) {
            return $currentStatus;
        }

        return $incomingStatus;
    }

    public function rank(?string $status, string $sport): int
    {
        if ($status === null || $status === '') {
            return 0;
        }

        return (int) (config("espn_sync.status_rank.{$sport}.{$status}")
            ?? config("espn_sync.status_rank.default.{$status}")
            ?? 0);
    }

    public function isFinal(?string $status, string $sport): bool
    {
        return $this->rank($status, $sport) >= $this->rank('STATUS_FINAL', $sport);
    }
}
