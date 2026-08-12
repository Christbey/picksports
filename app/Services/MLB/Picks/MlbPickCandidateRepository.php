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
    public function forDate(
        CarbonInterface|string $date,
        ?int $season = null,
        ?int $gameId = null,
        bool $compact = false,
    ): EloquentCollection {
        $window = $this->dateWindows->forDate($date);
        $query = PickCandidate::query()
            ->with(['game.homeTeam', 'game.awayTeam', 'team', 'player'])
            ->whereNull('superseded_at')
            ->whereHas('game', fn ($query) => $this->dateWindows->applyGameDateWindow($query, $window))
            ->orderByDesc('score');

        if ($season !== null) {
            $query->where('season', $season);
        }

        if ($gameId !== null) {
            $query->where('game_id', $gameId);
        }

        if ($compact) {
            $query->select([
                'id',
                'season',
                'game_id',
                'prediction_id',
                'team_id',
                'player_id',
                'market_type',
                'market_key',
                'side',
                'line',
                'price',
                'book',
                'market_probability',
                'no_vig_probability',
                'model_probability',
                'blend_probability',
                'edge_raw',
                'edge_no_vig',
                'projected_value',
                'score',
                'confidence',
                'status',
                'recommendation_label',
                'is_public',
                'is_tracking_only',
                'is_bet',
                'risk_flags',
                'reason_codes',
                'generated_at',
                'graded_at',
                'result_status',
                'result_profit_units',
                'closing_price',
                'closing_line',
                'clv',
            ]);
        }

        return $query->get();
    }
}
