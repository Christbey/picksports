<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\PickCandidate;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class MlbPickCandidateRepository
{
    public function __construct(
        private readonly SportsDateWindowService $dateWindows,
        private readonly BetDecisionRecorder $decisionRecorder,
    ) {}

    /**
     * @param  Collection<int,array<string,mixed>>  $payloads
     * @return EloquentCollection<int,PickCandidate>
     */
    public function persist(Collection $payloads): EloquentCollection
    {
        $rows = $payloads->map(function (array $payload): PickCandidate {
            $candidate = PickCandidate::query()->create($payload);
            $this->decisionRecorder->record($candidate);

            return $candidate;
        });

        return new EloquentCollection($rows->all());
    }

    /**
     * @return EloquentCollection<int,PickCandidate>
     */
    public function forDate(CarbonInterface|string $date, ?int $season = null): EloquentCollection
    {
        $window = $this->dateWindows->forDate($date);
        $query = PickCandidate::query()
            ->with(['game.homeTeam', 'game.awayTeam', 'team', 'player'])
            ->whereNull('superseded_at')
            ->whereHas('game', fn ($query) => $this->dateWindows->applyGameDateWindow($query, $window))
            ->orderByDesc('score');

        if ($season !== null) {
            $query->where('season', $season);
        }

        return $query->get();
    }
}
