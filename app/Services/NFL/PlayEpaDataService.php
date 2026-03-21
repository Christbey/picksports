<?php

namespace App\Services\NFL;

use App\Models\NFL\Player;
use App\Models\NFL\Team;

class PlayEpaDataService
{
    /**
     * @var array<int, true>
     */
    private const ADMIN_DOWN_VALUES = [
        -1 => true,
        0 => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ADMIN_PLAY_TYPES = [
        'coin toss' => true,
        'end period' => true,
        'end of game' => true,
        'official timeout' => true,
        'timeout' => true,
        'two-minute warning' => true,
        'injury timeout' => true,
        'penalty' => true,
    ];

    public function isEpaEligiblePlay(object $play): bool
    {
        $playType = strtolower(trim((string) ($play->play_type ?? '')));
        $playText = strtolower((string) ($play->play_text ?? ''));
        $down = is_numeric($play->down ?? null) ? (int) $play->down : null;
        $distance = is_numeric($play->distance ?? null) ? (int) $play->distance : null;
        $yardsToEndzone = is_numeric($play->yards_to_endzone ?? null) ? (int) $play->yards_to_endzone : null;

        if ($playType !== '' && isset(self::ADMIN_PLAY_TYPES[$playType])) {
            return false;
        }

        if (str_contains($playText, 'no play')) {
            return false;
        }

        if ($down === null || isset(self::ADMIN_DOWN_VALUES[$down]) || $down < 1 || $down > 4) {
            return false;
        }

        if ($distance === null || $distance < 0) {
            return false;
        }

        if ($yardsToEndzone === null || $yardsToEndzone < 1 || $yardsToEndzone > 100) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,int>
     */
    public function buildLastNameTeamMap(Team $homeTeam, Team $awayTeam): array
    {
        $map = [];
        $conflicts = [];

        $players = Player::query()
            ->whereIn('team_id', [$homeTeam->id, $awayTeam->id])
            ->select(['team_id', 'last_name'])
            ->get();

        foreach ($players as $player) {
            $lastName = $this->normalizeLastName((string) ($player->last_name ?? ''));
            if ($lastName === '') {
                continue;
            }

            $teamId = (int) $player->team_id;
            if (! isset($map[$lastName])) {
                $map[$lastName] = $teamId;

                continue;
            }

            if ($map[$lastName] !== $teamId) {
                $conflicts[$lastName] = true;
            }
        }

        foreach (array_keys($conflicts) as $lastName) {
            unset($map[$lastName]);
        }

        return $map;
    }

    /**
     * Attempts to infer possession team from play text actor names.
     *
     * @param  array<string,int>  $lastNameTeamMap
     */
    public function inferPossessionFromPlayText(
        string $playText,
        string $playType,
        array $lastNameTeamMap,
        int $homeTeamId,
        int $awayTeamId
    ): ?int {
        $actorTeamId = $this->inferActorTeamIdFromPlayText($playText, $lastNameTeamMap);
        if ($actorTeamId === null) {
            return null;
        }

        $type = strtolower(trim($playType));
        if (str_contains($type, 'kickoff')) {
            $normalizedText = strtolower($playText);
            if (str_contains($normalizedText, ' return') || str_contains($normalizedText, 'returns')) {
                // If the actor appears on a return description, they are typically the receiving team.
                return $actorTeamId;
            }

            return $actorTeamId === $homeTeamId ? $awayTeamId : $homeTeamId;
        }

        return $actorTeamId;
    }

    /**
     * @param  array<string,int>  $lastNameTeamMap
     */
    private function inferActorTeamIdFromPlayText(string $playText, array $lastNameTeamMap): ?int
    {
        if ($playText === '') {
            return null;
        }

        preg_match_all('/\b[A-Z]\.([A-Za-z\'\-]+)\b/', $playText, $matches);
        $lastNames = $matches[1] ?? [];

        foreach ($lastNames as $lastName) {
            $normalized = $this->normalizeLastName($lastName);
            if ($normalized === '' || ! isset($lastNameTeamMap[$normalized])) {
                continue;
            }

            return $lastNameTeamMap[$normalized];
        }

        return null;
    }

    private function normalizeLastName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z\'\-]/', '', $normalized) ?? '';

        return $normalized;
    }
}
