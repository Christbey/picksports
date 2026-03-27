<?php

namespace App\Services;

use App\DataTransferObjects\ESPN\GameData;
use App\Events\GameFinalized;
use App\Models\NBA\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GameFinalizationDispatcher
{
    /**
     * @var array<class-string<Model>, string>
     */
    private const SPORT_BY_GAME_MODEL = [
        Game::class => 'nba',
        \App\Models\NFL\Game::class => 'nfl',
        \App\Models\MLB\Game::class => 'mlb',
        \App\Models\CBB\Game::class => 'cbb',
        \App\Models\CFB\Game::class => 'cfb',
        \App\Models\WNBA\Game::class => 'wnba',
        \App\Models\WCBB\Game::class => 'wcbb',
    ];

    /**
     * @var array<string, class-string<Model>>
     */
    private const PREDICTION_MODEL_BY_SPORT = [
        'nba' => \App\Models\NBA\Prediction::class,
        'nfl' => \App\Models\NFL\Prediction::class,
        'mlb' => \App\Models\MLB\Prediction::class,
        'cbb' => \App\Models\CBB\Prediction::class,
        'cfb' => \App\Models\CFB\Prediction::class,
        'wnba' => \App\Models\WNBA\Prediction::class,
        'wcbb' => \App\Models\WCBB\Prediction::class,
    ];

    /**
     * @var array<string, class-string<Model>>
     */
    private const PLAYER_PROP_MODEL_BY_SPORT = [
        'nba' => \App\Models\NBA\PlayerProp::class,
        'nfl' => \App\Models\NFL\PlayerProp::class,
        'mlb' => \App\Models\MLB\PlayerProp::class,
        'cbb' => \App\Models\CBB\PlayerProp::class,
    ];

    public function dispatchIfFinalizedTransition(Model $game, ?string $previousStatus): void
    {
        $currentStatus = (string) ($game->status ?? '');

        $wasFinal = in_array((string) $previousStatus, GameData::finalStatuses(), true);
        $isFinal = in_array($currentStatus, GameData::finalStatuses(), true);

        if (! $isFinal) {
            return;
        }

        // Final status occasionally arrives before box score fields are populated.
        // Skip dispatch until both team scores are present.
        if (! isset($game->home_score, $game->away_score)) {
            return;
        }

        $sport = self::SPORT_BY_GAME_MODEL[$game::class] ?? null;
        if ($sport === null) {
            return;
        }

        if ($wasFinal && ! $this->hasUngradedFinalizationWork($game, $sport)) {
            return;
        }

        event(new GameFinalized(
            sport: $sport,
            gameId: (int) $game->getKey(),
            season: isset($game->season) ? (int) $game->season : null,
            gameModelClass: $game::class,
        ));
    }

    private function hasUngradedFinalizationWork(Model $game, string $sport): bool
    {
        $predictionModel = self::PREDICTION_MODEL_BY_SPORT[$sport] ?? null;
        if ($predictionModel !== null && $this->hasUngradedRows($predictionModel, $game)) {
            return true;
        }

        $playerPropModel = self::PLAYER_PROP_MODEL_BY_SPORT[$sport] ?? null;

        return $playerPropModel !== null && $this->hasUngradedRows($playerPropModel, $game);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function hasUngradedRows(string $modelClass, Model $game): bool
    {
        return $modelClass::query()
            ->where('game_id', $game->getKey())
            ->where(function (Builder $query): void {
                $query->whereNull('graded_at');
            })
            ->exists();
    }
}
