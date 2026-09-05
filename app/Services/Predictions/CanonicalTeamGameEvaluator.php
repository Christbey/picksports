<?php

namespace App\Services\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class CanonicalTeamGameEvaluator
{
    private const MINIMUM_FINAL_MINUTES_AFTER_TIP = 90;

    /** @var array<string, class-string<Model>> */
    private const GAME_MODELS = [
        'cbb' => CbbGame::class,
        'cfb' => CfbGame::class,
        'nba' => NbaGame::class,
        'nfl' => NflGame::class,
        'mlb' => MlbGame::class,
        'wcbb' => WcbbGame::class,
        'wnba' => WnbaGame::class,
    ];

    public function __construct(
        private readonly SportEventResultRecorder $results,
        private readonly CanonicalPredictionEvaluator $evaluator,
    ) {}

    public function evaluate(Model $game, string $sport): ?PredictionEvaluation
    {
        $sport = strtolower(trim($sport));
        $gameClass = self::GAME_MODELS[$sport] ?? null;

        if ($gameClass === null || ! $game instanceof $gameClass) {
            throw new \InvalidArgumentException("Canonical team-game evaluation does not support {$sport} game input.");
        }

        $game->loadMissing('sportEvent');
        $event = $game->getRelation('sportEvent');

        if ($event === null
            || $game->getAttribute('status') !== 'STATUS_FINAL'
            || $game->getAttribute('home_score') === null
            || $game->getAttribute('away_score') === null
            || ! $this->hasFinalClock($game->getAttribute('game_clock'))
            || CarbonImmutable::now('UTC')->lt(CarbonImmutable::parse($event->starts_at)->utc()->addMinutes(self::MINIMUM_FINAL_MINUTES_AFTER_TIP))) {
            return null;
        }

        $prediction = CanonicalPrediction::query()
            ->where('sport_event_id', $event->getKey())
            ->where('phase', 'pregame')
            ->whereIn('publication_state', ['published', 'superseded'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $event->starts_at)
            ->orderByDesc('revision')
            ->first();

        if ($prediction === null) {
            return null;
        }

        $result = $this->results->record(
            $event,
            (int) $game->getAttribute('home_score'),
            (int) $game->getAttribute('away_score'),
            source: 'espn',
            sourceReference: (string) ($game->getAttribute('espn_event_id') ?: $game->getKey()),
            observedAt: CarbonImmutable::parse($game->updated_at),
            finalizedAt: $game->getAttribute('completed_at') === null
                ? null
                : CarbonImmutable::parse($game->getAttribute('completed_at')),
            metadata: ["{$sport}_game_id" => $game->getKey()],
        );

        return $this->evaluator->evaluate($prediction, $result);
    }

    private function hasFinalClock(mixed $clock): bool
    {
        $normalized = trim((string) $clock);

        return $normalized === ''
            || in_array($normalized, ['0', '0.0', '0:00', '00:00'], true);
    }
}
