<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Resources\BettingRecommendationResource;
use App\Http\Resources\UpcomingPlayerPropResource;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerPropController extends AbstractSportsApiController
{
    public function __construct(
        protected PlayerPropAnalyzer $analyzer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sportSlug = $this->resolveSportSlug($request);
        $sportCode = $this->resolveAnalyzerSport($sportSlug);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'game' => ['nullable', 'integer'],
            'market' => ['nullable', 'string', 'max:100'],
        ]);

        $resolvedDate = $this->resolveBoardDate(
            $sportCode,
            $validated['date'] ?? null
        );
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;
        $marketFilter = $validated['market'] ?? null;

        $recommendations = $this->analyzer->analyzeProps(
            sport: $sportCode,
            minGames: 3,
            dateFilter: $resolvedDate,
            gameFilter: $gameFilter,
            marketFilter: $marketFilter
        );

        return response()->json([
            'sport' => $sportCode,
            'data' => BettingRecommendationResource::collection($recommendations)->resolve(),
            'dates' => $this->analyzer->getAvailableDatesForSport($sportCode)->values(),
            'games' => $this->analyzer->getAvailableGamesForSport($sportCode, $resolvedDate)->values(),
            'markets' => $this->analyzer->getAvailableMarketsForSport($sportCode, $resolvedDate, $gameFilter)->values(),
            'filters' => [
                'date' => $resolvedDate,
                'game' => $gameFilter,
                'market' => $marketFilter,
            ],
        ]);
    }

    public function byPlayer($player, Request $request): JsonResponse
    {
        $sportSlug = $this->resolveSportSlug($request);
        $sportConfig = $this->resolveSportConfig($sportSlug);
        $playerId = $this->requireNumericId($player);

        $playerModel = $sportConfig['player_model'];
        $playerPropModel = $sportConfig['player_prop_model'];

        $player = $playerModel::query()->findOrFail($playerId);

        $props = $playerPropModel::query()
            ->whereHas('game', function ($query) {
                $query->where('status', 'STATUS_SCHEDULED')
                    ->whereDate('game_date', '>=', now());
            })
            ->where(function ($query) use ($player) {
                $query->where('player_id', $player->getKey());

                $fallbackName = $this->playerFallbackName($player);
                if ($fallbackName !== null && $fallbackName !== '') {
                    $query->orWhere('player_name', 'like', '%'.$fallbackName.'%');
                }
            })
            ->with(['player.team', 'game.homeTeam', 'game.awayTeam'])
            ->orderByDesc('fetched_at')
            ->get()
            ->groupBy('market')
            ->map(fn ($marketProps) => $marketProps->first())
            ->values();

        return response()->json([
            'data' => UpcomingPlayerPropResource::collection($props)->resolve(),
        ]);
    }

    protected function resolveSportSlug(Request $request): string
    {
        $sport = strtolower((string) $request->route('sport', ''));

        if ($sport === '') {
            abort(404);
        }

        return $sport;
    }

    protected function resolveAnalyzerSport(string $sportSlug): string
    {
        return match ($sportSlug) {
            'nba' => 'NBA',
            'cbb' => 'CBB',
            'nfl' => 'NFL',
            'mlb' => 'MLB',
            default => throw new \InvalidArgumentException("Unsupported player props sport: {$sportSlug}"),
        };
    }

    /**
     * @return array{player_model: class-string, player_prop_model: class-string}
     */
    protected function resolveSportConfig(string $sportSlug): array
    {
        $namespace = (string) data_get(config('sports.domains'), "{$sportSlug}.namespace");
        if ($namespace === '') {
            abort(404);
        }

        $playerModel = "App\\Models\\{$namespace}\\Player";
        $playerPropModel = "App\\Models\\{$namespace}\\PlayerProp";

        if (! class_exists($playerModel) || ! class_exists($playerPropModel)) {
            abort(404);
        }

        return [
            'player_model' => $playerModel,
            'player_prop_model' => $playerPropModel,
        ];
    }

    protected function resolveBoardDate(string $sportCode, ?string $requestedDate): ?string
    {
        if ($requestedDate !== null && $requestedDate !== '') {
            return $requestedDate;
        }

        $dates = $this->analyzer->getAvailableDatesForSport($sportCode);
        if ($sportCode !== 'NBA' || $dates->isEmpty()) {
            return $requestedDate;
        }

        $today = Carbon::today()->toDateString();
        $dateValues = $dates->pluck('value');

        if ($dateValues->contains($today)) {
            return $today;
        }

        $futureDates = $dateValues
            ->filter(fn ($date) => is_string($date) && $date > $today)
            ->values();

        if ($futureDates->isNotEmpty()) {
            return (string) $futureDates->first();
        }

        return $dateValues->isNotEmpty() ? (string) $dateValues->last() : null;
    }

    protected function playerFallbackName(object $player): ?string
    {
        foreach (['last_name', 'full_name', 'display_name', 'name'] as $field) {
            $value = data_get($player, $field);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
