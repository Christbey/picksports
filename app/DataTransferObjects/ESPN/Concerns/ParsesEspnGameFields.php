<?php

namespace App\DataTransferObjects\ESPN\Concerns;

trait ParsesEspnGameFields
{
    use CastsEspnValues;

    /**
     * Normalize ESPN status value to uppercase STATUS_* format.
     */
    public static function normalizeStatus(string $status): string
    {
        $trimmedStatus = trim($status);

        // If status is already in STATUS_* format, return it as-is.
        if (str_starts_with($trimmedStatus, 'STATUS_')) {
            return $trimmedStatus;
        }

        $statusMap = [
            'scheduled' => 'STATUS_SCHEDULED',
            'pre' => 'STATUS_SCHEDULED',
            'in progress' => 'STATUS_IN_PROGRESS',
            'in_progress' => 'STATUS_IN_PROGRESS',
            'in' => 'STATUS_IN_PROGRESS',
            'final' => 'STATUS_FINAL',
            'post' => 'STATUS_FINAL',
            'postponed' => 'STATUS_POSTPONED',
            'canceled' => 'STATUS_CANCELED',
            'cancelled' => 'STATUS_CANCELED',
            'suspended' => 'STATUS_SUSPENDED',
            'delayed' => 'STATUS_DELAYED',
        ];

        $normalizedKey = strtolower($trimmedStatus);

        return $statusMap[$normalizedKey] ?? 'STATUS_SCHEDULED';
    }

    /**
     * @return array{game_date:?string,game_time:?string}
     */
    public static function extractDateParts(?string $dateTime): array
    {
        if (! $dateTime) {
            return [
                'game_date' => null,
                'game_time' => null,
            ];
        }

        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return [
                'game_date' => null,
                'game_time' => null,
            ];
        }

        return [
            'game_date' => date('Y-m-d', $timestamp),
            'game_time' => date('H:i:s', $timestamp),
        ];
    }

    /**
     * @param  array<string, mixed>  $game
     * @return array<string, mixed>
     */
    protected static function competitionFromGame(array $game): array
    {
        return $game['competitions'][0] ?? [];
    }

    /**
     * @param  array<string, mixed>  $competition
     * @return array<string, mixed>
     */
    protected static function homeCompetitor(array $competition): array
    {
        return collect($competition['competitors'] ?? [])->firstWhere('homeAway', 'home') ?? [];
    }

    /**
     * @param  array<string, mixed>  $competition
     * @return array<string, mixed>
     */
    protected static function awayCompetitor(array $competition): array
    {
        return collect($competition['competitors'] ?? [])->firstWhere('homeAway', 'away') ?? [];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    protected static function competitorTeamEspnId(array $competitor): string
    {
        return self::stringOrEmpty($competitor['team']['id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    protected static function competitorScore(array $competitor): ?int
    {
        return isset($competitor['score']) && is_numeric($competitor['score'])
            ? (int) $competitor['score']
            : null;
    }

    /**
     * @param  array<string, mixed>  $competition
     */
    protected static function venueName(array $competition): ?string
    {
        return $competition['venue']['fullName'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $competition
     */
    protected static function venueCity(array $competition): ?string
    {
        return $competition['venue']['address']['city'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $competition
     */
    protected static function venueState(array $competition): ?string
    {
        return $competition['venue']['address']['state'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $competition
     * @return array<int, string>
     */
    protected static function broadcastNetworks(array $competition): array
    {
        return collect($competition['broadcasts'] ?? [])->pluck('names')->flatten()->toArray();
    }
}
