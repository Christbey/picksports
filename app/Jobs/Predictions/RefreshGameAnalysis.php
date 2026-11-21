<?php

namespace App\Jobs\Predictions;

use App\Models\User;
use App\Services\Api\V2\GameAnalysisAccess;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\PredictionReviewRunner;
use App\Services\Research\GameResearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The explicit write entry point, shared by publisher and authorized recovery. */
class RefreshGameAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public string $sport, public int $gameId, public ?int $requestedBy = null) {}

    public function uniqueId(): string
    {
        // A queued automatic refresh cannot swallow an explicit authorized retry.
        // The durable review lease still prevents duplicate provider admission.
        return $this->sport.':'.$this->gameId.':'.($this->requestedBy !== null ? 'admin:'.$this->requestedBy : 'automatic');
    }

    public function handle(SportContextResolver $sports, GameResearchService $research, PredictionReviewCoordinator $reviews, PredictionReviewRunner $runner, GameAnalysisAccess $access): void
    {
        $context = $sports->find($this->sport);
        if ($this->requestedBy !== null && ! User::find($this->requestedBy)?->isAdmin()) {
            return;
        }
        if ($this->requestedBy !== null) {
            try {
                $access->authorize(User::find($this->requestedBy), $this->sport);
            } catch (HttpExceptionInterface) {
                return;
            }
        }
        if ($context === null || (! $research->enabled($this->sport) && ! $reviews->enabled($this->sport))) {
            return;
        }
        $model = $context->models['game'];
        $game = $model::query()->with(['homeTeam', 'awayTeam'])->find($this->gameId);
        if ($game === null) {
            return;
        }
        if ($research->enabled($this->sport)) {
            $research->assess($game, $this->sport);
        }
        $evidence = $research->forGame($game, $this->sport);
        $work = $this->requestedBy !== null
            ? $reviews->retryGame($game, $this->sport, $evidence)
            : $reviews->reconcileGame($game, $this->sport, $evidence);
        if ($work !== null) {
            $runner->run($work);
        }
    }
}
