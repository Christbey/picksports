<?php

namespace App\Services\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\PredictionEvaluation;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        $prediction = $this->predictionForGame($game, $sport);
        if ($prediction === null) {
            return null;
        }
        $event = $game->getRelation('sportEvent');

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

    public function hasPendingEvaluation(Model $game, string $sport): bool
    {
        return $this->pendingGameIds(collect([$game]), $sport)->contains($game->getKey());
    }

    /** @return Collection<int, int> */
    public function pendingGameIds(Collection $games, string $sport): Collection
    {
        $sport = strtolower(trim($sport));
        $pending = collect();
        foreach ($games->chunk(25) as $batch) {
            (new \Illuminate\Database\Eloquent\Collection($batch->all()))->loadMissing('sportEvent');
            $eligible = $batch->filter(fn ($game) => $this->eligibleEvent($game, $sport) !== null)->keyBy('sport_event_id');
            if ($eligible->isEmpty()) {
                continue;
            }
            $predictions = CanonicalPrediction::query()->select(['predictions.id', 'predictions.sport_event_id', 'predictions.sport', 'predictions.calculation_run_id'])
                ->join('sport_events', 'sport_events.id', '=', 'predictions.sport_event_id')
                ->whereIn('predictions.sport_event_id', $eligible->keys())->where('predictions.sport', $sport)
                ->where('predictions.id', function ($query) use ($sport): void {
                    $query->from('predictions as candidate')->select('candidate.id')->whereColumn('candidate.sport_event_id', 'predictions.sport_event_id')
                        ->where('candidate.sport', $sport)->where('candidate.phase', 'pregame')->whereIn('candidate.publication_state', ['published', 'superseded'])
                        ->whereNotNull('candidate.published_at')->whereColumn('candidate.published_at', '<=', 'sport_events.starts_at')
                        ->orderByDesc('candidate.revision')->limit(1);
                })->get();
            $results = SportEventResult::query()->whereIn('sport_event_id', $eligible->keys())
                ->where('id', function ($query): void {
                    $query->from('sport_event_results as latest')->select('latest.id')->whereColumn('latest.sport_event_id', 'sport_event_results.sport_event_id')
                        ->orderByDesc('latest.revision')->limit(1);
                })->get();
            $current = $this->evaluator->currentEvaluationPredictionIds($predictions, $results);
            $results = $results->keyBy('sport_event_id');
            foreach ($predictions as $prediction) {
                $game = $eligible->get($prediction->sport_event_id);
                $result = $results->get($prediction->sport_event_id);
                if ($result === null || $result->status !== 'official' || $result->home_score !== (int) $game->home_score
                    || $result->away_score !== (int) $game->away_score || ! $current->contains($prediction->id)) {
                    $pending->push($game->getKey());
                }
            }
        }

        return $pending;
    }

    private function predictionForGame(Model $game, string $sport): ?CanonicalPrediction
    {
        $event = $this->eligibleEvent($game, $sport);
        if ($event === null) {
            return null;
        }

        return CanonicalPrediction::query()
            ->where('sport_event_id', $event->getKey())
            ->where('sport', $sport)
            ->where('phase', 'pregame')
            ->whereIn('publication_state', ['published', 'superseded'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $event->starts_at)
            ->orderByDesc('revision')
            ->first();

    }

    private function eligibleEvent(Model $game, string $sport): ?SportEvent
    {
        $gameClass = self::GAME_MODELS[$sport] ?? null;

        if ($gameClass === null || ! $game instanceof $gameClass) {
            throw new \InvalidArgumentException("Canonical team-game evaluation does not support {$sport} game input.");
        }

        $game->loadMissing('sportEvent');
        $event = $game->getRelation('sportEvent');

        if ($event === null
            || $event->sport !== $sport
            || $game->getAttribute('status') !== 'STATUS_FINAL'
            || $game->getAttribute('home_score') === null
            || $game->getAttribute('away_score') === null
            || ! $this->hasFinalClock($game->getAttribute('game_clock'))
            || CarbonImmutable::now('UTC')->lt(CarbonImmutable::parse($event->starts_at)->utc()->addMinutes(self::MINIMUM_FINAL_MINUTES_AFTER_TIP))) {
            return null;
        }

        return $event;
    }

    private function hasFinalClock(mixed $clock): bool
    {
        $normalized = trim((string) $clock);

        return $normalized === ''
            || in_array($normalized, ['0', '0.0', '0:00', '00:00'], true);
    }
}
