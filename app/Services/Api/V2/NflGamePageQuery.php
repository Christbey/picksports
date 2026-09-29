<?php

namespace App\Services\Api\V2;

use App\Http\Resources\Api\V2\SportGameResource;
use App\Http\Resources\Api\V2\SportStatResource;
use App\Services\Sports\GameMatchupContextService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class NflGamePageQuery
{
    public function __construct(
        private readonly SportGameQuery $games,
        private readonly GamePredictionPayload $predictions,
        private readonly GameMatchupContextService $matchups,
    ) {}

    public function get(SportContext $context, string $id, Request $request): array
    {
        $game = $this->games->find($context, $id, $request->user());
        $game->setAttribute('matchup_context', $this->matchups->forGame($game));
        $game->loadMissing('teamStats.team');
        $prediction = null;
        $predictionSource = null;
        try {
            $payload = $this->predictions->forGame($context, (string) $game->getKey(), $request);
            $prediction = $payload['data'];
            $predictionSource = $payload['meta']['prediction_source'];
        } catch (ModelNotFoundException) {
            // A scheduled game can exist before its first prediction.
        }

        return [
            'data' => [
                'game' => new SportGameResource($game, $context),
                'prediction' => $prediction,
                'team_stats' => $game->teamStats->map(fn ($stat) => new SportStatResource($stat, $context, 'team'))->values(),
            ],
            'meta' => [
                'version' => 'v2', 'sport' => 'nfl', 'contract' => 'sports.games.page.show',
                'game_id' => (int) $game->getKey(), 'prediction_source' => $predictionSource,
                'deferred' => ['team_trends', 'recent_games', 'depth_charts', 'research', 'matchup_signals', 'market_history'],
            ],
        ];
    }
}
