<?php

namespace App\Actions\ESPN\Concerns;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\GameFinalizationDispatcher;
use App\Support\EspnGameStatusResolver;
use Illuminate\Database\Eloquent\Model;

trait UpdatesGameFromSummary
{
    protected function updateGameFromSummary(array $gameData, Model $game): bool
    {
        $previousStatus = (string) ($game->status ?? '');

        $header = $gameData['header'] ?? [];
        $competitions = $header['competitions'] ?? [];
        $competition = $competitions[0] ?? [];

        $competitors = $competition['competitors'] ?? [];
        $status = $competition['status'] ?? [];

        $homeTeam = collect($competitors)->firstWhere('homeAway', 'home');
        $awayTeam = collect($competitors)->firstWhere('homeAway', 'away');

        $broadcasts = $competition['broadcasts'] ?? [];
        $broadcastNetworks = collect($broadcasts)->pluck('names')->flatten()->toArray();

        $normalizedStatus = GameData::normalizeStatus((string) ($status['type']['name'] ?? 'scheduled'));
        $parts = explode('\\', get_class($game));
        $sport = strtolower($parts[2] ?? '');
        /** @var EspnGameStatusResolver $resolver */
        $resolver = app(EspnGameStatusResolver::class);
        $resolvedStatus = $resolver->resolveForUpdate(
            (string) ($game->status ?? ''),
            $normalizedStatus,
            'summary',
            $sport,
        );

        $game->update([
            'status' => $resolvedStatus,
            'home_score' => isset($homeTeam['score']) ? (int) $homeTeam['score'] : null,
            'away_score' => isset($awayTeam['score']) ? (int) $awayTeam['score'] : null,
            'home_linescores' => $homeTeam['linescores'] ?? null,
            'away_linescores' => $awayTeam['linescores'] ?? null,
            'period' => isset($status['period']) ? (int) $status['period'] : null,
            'game_clock' => $status['displayClock'] ?? null,
            'broadcast_networks' => ! empty($broadcastNetworks) ? $broadcastNetworks : null,
        ]);

        app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game->fresh(), $previousStatus);

        return true;
    }
}
