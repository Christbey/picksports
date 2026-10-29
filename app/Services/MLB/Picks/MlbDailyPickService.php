<?php

namespace App\Services\MLB\Picks;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MlbDailyPickService
{
    public function __construct(
        private readonly SportsDateWindowService $dateWindows,
        private readonly MlbMoneylineCandidateBuilder $moneyline,
        private readonly MlbRunLineCandidateBuilder $runLine,
        private readonly MlbTotalCandidateBuilder $total,
        private readonly MlbFirstInningCandidateBuilder $firstInning,
        private readonly MlbFirstFiveCandidateBuilder $firstFive,
        private readonly MlbFirstThreeCandidateBuilder $firstThree,
        private readonly MlbPlayerPropCandidateBuilder $props,
        private readonly MlbPickCandidateScorer $scorer,
        private readonly MlbPickPromotionGate $promotionGate,
        private readonly MlbPickCandidateRepository $repository,
        private readonly MlbDailyTopPickSelector $topPickSelector,
    ) {}

    /**
     * @param  list<string>  $markets
     * @return array{date:string,slate_games:int,priced_games:int,candidate_count:int,tracking_only_count:int,public_promoted_count:int,candidates:Collection<int,PickCandidate>,top_picks:Collection<int,PickCandidate>,blocked_reasons:list<string>,dry_run:bool}
     */
    public function generateForDate(CarbonInterface|string $date, array $markets = [], bool $dryRun = false, ?int $season = null, ?int $limit = null): array
    {
        $window = $this->dateWindows->forDate($date);
        $marketSet = $this->marketSet($markets);
        $season ??= (int) config('mlb.season.default');
        $generationRunId = (string) Str::uuid();
        $generatedAt = now();
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam', 'playerProps'])
            ->where('season', $season)
            ->where('status', config('mlb.statuses.scheduled', 'STATUS_SCHEDULED'))
            ->tap(fn ($query) => $this->dateWindows->applyGameDateWindow($query, $window))
            ->orderBy('game_date')
            ->get();

        $forecasts = app(BettingForecastSource::class)->forGames('mlb', $games->pluck('id')->all(), $season)->keyBy('game.id');

        $payloads = collect();
        foreach ($games as $game) {
            $prediction = $forecasts->get($game->id);
            $candidateData = collect();

            if ($prediction instanceof BettingForecast) {
                if ($marketSet['moneyline']) {
                    $candidateData = $candidateData->merge($this->moneyline->build($prediction));
                }
                if ($marketSet['run_line']) {
                    $candidateData = $candidateData->merge($this->runLine->build($prediction));
                }
                if ($marketSet['total']) {
                    $candidateData = $candidateData->merge($this->total->build($prediction));
                }
                if ($marketSet['first_inning']) {
                    $candidateData = $candidateData->merge($this->firstInning->build($prediction));
                }
                if ($marketSet['first_5']) {
                    $candidateData = $candidateData->merge($this->firstFive->build($prediction));
                }
                if ($marketSet['first_3']) {
                    $candidateData = $candidateData->merge($this->firstThree->build($prediction));
                }
            }

            if ($marketSet['props']) {
                $candidateData = $candidateData->merge($this->props->build($game, $prediction));
            }

            foreach ($candidateData as $candidate) {
                $payloads->push($this->payload($candidate, $generationRunId, $generatedAt, $prediction));
            }
        }

        if (! $dryRun) {
            PickCandidate::query()
                ->whereIn('game_id', $games->pluck('id')->all())
                ->where('season', $season)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => $generatedAt]);
        }

        $candidates = $dryRun
            ? new EloquentCollection($payloads->map(fn (array $payload): PickCandidate => new PickCandidate($payload))->all())
            : $this->repository->persist($payloads);
        $topPicks = $this->topPickSelector->select($candidates->toBase(), $limit);

        return [
            'date' => $window->localStartDate(),
            'slate_games' => $games->count(),
            'priced_games' => $games->filter(fn (Game $game): bool => ! empty(data_get($game->odds_data, 'bookmakers')))->count(),
            'candidate_count' => $candidates->count(),
            'tracking_only_count' => $candidates->where('is_tracking_only', true)->count(),
            'public_promoted_count' => $candidates->where('is_public', true)->count(),
            'candidates' => $candidates->toBase(),
            'top_picks' => $topPicks,
            'blocked_reasons' => ['mlb_public_promotion_unvalidated'],
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(
        MlbPickCandidateData $candidate,
        string $generationRunId,
        CarbonInterface $generatedAt,
        ?BettingForecast $forecast = null,
    ): array {
        $scored = $this->scorer->score($candidate);
        $promotion = $this->promotionGate->apply($scored['internal_label'], $candidate->marketType);
        if ($candidate->canonicalPredictionId !== null) {
            $promotion = [
                'status' => 'tracking_only', 'recommendation_label' => 'tracking_only',
                'is_public' => false, 'is_tracking_only' => true, 'is_bet' => false,
                'blocked_reasons' => ['canonical_candidate_requires_validation'],
            ];
        }
        $payload = $candidate->toPayload();

        return [
            ...$payload,
            'generation_run_id' => $generationRunId,
            'decision_hash' => hash('sha256', json_encode([
                $generationRunId,
                $payload['game_id'] ?? null,
                $payload['market_type'] ?? null,
                $payload['market_key'] ?? null,
                $payload['side'] ?? null,
                $payload['line'] ?? null,
                $payload['price'] ?? null,
                $payload['book'] ?? null,
                $payload['player_id'] ?? null,
            ])),
            'score' => $scored['score'],
            'confidence' => $scored['confidence'],
            'status' => $promotion['status'],
            'recommendation_label' => $promotion['recommendation_label'],
            'is_public' => $promotion['is_public'],
            'is_tracking_only' => $promotion['is_tracking_only'],
            'is_bet' => $promotion['is_bet'],
            'reason_codes' => $scored['reason_codes'],
            'risk_flags' => $scored['risk_flags'],
            'feature_snapshot' => [
                ...$scored['feature_snapshot'],
                'promotion_blocked_reasons' => $promotion['blocked_reasons'],
                ...($forecast?->isCanonical() ? ['prediction_lineage' => [
                    'source' => 'canonical', 'prediction_id' => $forecast->canonicalPredictionId(),
                    'public_id' => $forecast->source->public_id,
                    'calculation_run_id' => $forecast->source->calculation_run_id,
                    'event_input_snapshot_id' => $forecast->source->calculationRun?->event_input_snapshot_id,
                ]] : []),
            ],
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * @param  list<string>  $markets
     * @return array{moneyline:bool,run_line:bool,total:bool,first_inning:bool,first_3:bool,first_5:bool,props:bool}
     */
    private function marketSet(array $markets): array
    {
        $requested = collect($markets)->map(fn (string $market): string => strtolower(trim($market)))->filter()->all();
        $enabled = fn (string $market): bool => empty($requested)
            ? (bool) config("mlb.picks.markets.{$market}", true)
            : in_array($market, $requested, true);

        return [
            'moneyline' => $enabled('moneyline'),
            'run_line' => $enabled('run_line') || $enabled('spread'),
            'total' => $enabled('total') || $enabled('totals'),
            'first_inning' => $enabled('first_inning') || $enabled('first_1') || $enabled('yrfi') || $enabled('nrfi'),
            'first_3' => $enabled('first_3') || $enabled('f3'),
            'first_5' => $enabled('first_5') || $enabled('f5'),
            'props' => $enabled('props') || $enabled('player_prop') || $enabled('player_props'),
        ];
    }
}
