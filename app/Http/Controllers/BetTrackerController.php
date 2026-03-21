<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserBetResource;
use App\Models\UserBet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BetTrackerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $predictionId = $request->filled('prediction_id') ? $request->integer('prediction_id') : null;
        $predictionType = $request->filled('prediction_type') ? $request->string('prediction_type')->toString() : null;

        $betQuery = UserBet::where('user_id', $userId)
            ->when($predictionId !== null, fn (Builder $query) => $query->where('prediction_id', $predictionId))
            ->when($predictionType !== null, fn (Builder $query) => $query->where('prediction_type', $predictionType));

        $bets = $betQuery
            ->with('prediction')
            ->orderBy('placed_at', 'desc')
            ->paginate(20);

        $statistics = $this->calculateStatistics($userId);

        return response()->json([
            'bets' => UserBetResource::collection($bets)->response()->getData(),
            'statistics' => $statistics,
            'tracking' => $predictionId !== null && $predictionType !== null
                ? $this->buildPredictionTrackingSummary($predictionId, $predictionType, $bets)
                : null,
        ]);
    }

    public function store(Request $request): UserBetResource
    {
        $validated = $request->validate([
            'prediction_id' => 'nullable|integer',
            'prediction_type' => 'nullable|string',
            'bet_amount' => 'required|numeric|min:0',
            'odds' => 'required|string',
            'bet_type' => 'required|in:spread,moneyline,total_over,total_under',
            'selection_side' => 'nullable|string|max:20',
            'selection_label' => 'nullable|string|max:255',
            'line' => 'nullable|numeric',
            'notes' => 'nullable|string|max:1000',
            'placed_at' => 'nullable|date',
        ]);

        $this->validateSelectionSide($validated['bet_type'], $validated['selection_side'] ?? null);

        $bet = UserBet::create([
            'user_id' => $request->user()->id,
            'prediction_id' => $validated['prediction_id'] ?? null,
            'prediction_type' => $validated['prediction_type'] ?? null,
            'bet_amount' => $validated['bet_amount'],
            'odds' => $validated['odds'],
            'bet_type' => $validated['bet_type'],
            'selection_side' => $validated['selection_side'] ?? null,
            'selection_label' => $validated['selection_label'] ?? $this->fallbackSelectionLabel($validated),
            'line' => $validated['line'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'placed_at' => $validated['placed_at'] ?? now(),
        ]);

        return new UserBetResource($bet);
    }

    public function update(Request $request, UserBet $bet): UserBetResource
    {
        if ($bet->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'bet_amount' => 'sometimes|numeric|min:0',
            'odds' => 'sometimes|string',
            'bet_type' => 'sometimes|in:spread,moneyline,total_over,total_under',
            'selection_side' => 'sometimes|nullable|string|max:20',
            'selection_label' => 'sometimes|nullable|string|max:255',
            'line' => 'sometimes|nullable|numeric',
            'result' => 'sometimes|in:pending,won,lost,push',
            'profit_loss' => 'sometimes|numeric',
            'notes' => 'nullable|string|max:1000',
            'placed_at' => 'sometimes|date',
            'settled_at' => 'nullable|date',
        ]);

        $betType = $validated['bet_type'] ?? $bet->bet_type;
        $selectionSide = array_key_exists('selection_side', $validated)
            ? $validated['selection_side']
            : $bet->selection_side;

        $this->validateSelectionSide($betType, $selectionSide);

        if (
            ! array_key_exists('selection_label', $validated)
            && ($selectionSide !== null || array_key_exists('line', $validated) || array_key_exists('bet_type', $validated))
        ) {
            $validated['selection_label'] = $this->fallbackSelectionLabel([
                'bet_type' => $betType,
                'selection_side' => $selectionSide,
                'line' => $validated['line'] ?? $bet->line,
            ]);
        }

        if (isset($validated['result']) && $validated['result'] !== 'pending' && ! $bet->settled_at) {
            $validated['settled_at'] = now();
        }

        $bet->update($validated);

        return new UserBetResource($bet->fresh());
    }

    public function destroy(Request $request, UserBet $bet): JsonResponse
    {
        if ($bet->user_id !== $request->user()->id) {
            abort(403);
        }

        $bet->delete();

        return response()->json(null, 204);
    }

    public function export(Request $request): StreamedResponse
    {
        $bets = UserBet::where('user_id', $request->user()->id)
            ->with('prediction')
            ->orderBy('placed_at', 'desc')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="my-bets-'.now()->format('Y-m-d').'.csv"',
        ];

        $callback = function () use ($bets) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Date Placed',
                'Sport',
                'Selection',
                'Bet Type',
                'Amount',
                'Odds',
                'Result',
                'Profit/Loss',
                'Date Settled',
                'Notes',
            ]);

            foreach ($bets as $bet) {
                fputcsv($file, [
                    $bet->placed_at->format('Y-m-d H:i'),
                    class_basename($bet->prediction_type),
                    $bet->selection_label ?? '',
                    $bet->bet_type,
                    '$'.$bet->bet_amount,
                    $bet->odds,
                    ucfirst($bet->result),
                    $bet->profit_loss ? '$'.$bet->profit_loss : '',
                    $bet->settled_at?->format('Y-m-d H:i') ?? '',
                    $bet->notes ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function calculateStatistics(int $userId): array
    {
        $allBets = UserBet::where('user_id', $userId)->get();

        $totalBets = $allBets->count();
        $totalWagered = $allBets->sum('bet_amount');

        $settledBets = $allBets->whereIn('result', ['won', 'lost', 'push']);
        $wins = $settledBets->where('result', 'won')->count();
        $losses = $settledBets->where('result', 'lost')->count();
        $pushes = $settledBets->where('result', 'push')->count();

        $winRate = $settledBets->count() > 0
            ? round(($wins / $settledBets->count()) * 100, 1)
            : 0;

        $totalProfit = $allBets->sum('profit_loss') ?? 0;
        $roi = $totalWagered > 0
            ? round(($totalProfit / $totalWagered) * 100, 1)
            : 0;

        return [
            'total_bets' => $totalBets,
            'total_wagered' => $totalWagered,
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => $winRate,
            'total_profit' => $totalProfit,
            'roi' => $roi,
        ];
    }

    protected function validateSelectionSide(string $betType, ?string $selectionSide): void
    {
        $validSides = match ($betType) {
            'spread', 'moneyline' => ['home', 'away'],
            'total_over' => ['over'],
            'total_under' => ['under'],
            default => [],
        };

        if ($selectionSide === null || in_array($selectionSide, $validSides, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'selection_side' => 'The selected side does not match the bet type.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function fallbackSelectionLabel(array $validated): ?string
    {
        $selectionSide = $validated['selection_side'] ?? null;
        $line = $validated['line'] ?? null;

        if ($selectionSide === null) {
            return null;
        }

        if (($validated['bet_type'] ?? null) === 'moneyline') {
            return strtoupper((string) $selectionSide).' ML';
        }

        if (in_array($validated['bet_type'] ?? null, ['total_over', 'total_under'], true)) {
            return ucfirst((string) $selectionSide).($line !== null ? ' '.$line : '');
        }

        return strtoupper((string) $selectionSide).($line !== null ? ' '.($line > 0 ? '+' : '').$line : '');
    }

    protected function buildPredictionTrackingSummary(
        int $predictionId,
        string $predictionType,
        LengthAwarePaginator $userBetPaginator,
    ): array {
        $predictionBets = UserBet::query()
            ->where('prediction_id', $predictionId)
            ->where('prediction_type', $predictionType)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'user_id',
                'bet_type',
                'selection_side',
                'selection_label',
                'bet_amount',
                'odds',
                'line',
                'placed_at',
            ]);

        $latestByUserAndMarket = $predictionBets->unique(
            fn (UserBet $bet) => $bet->user_id.'|'.$this->marketKey($bet->bet_type)
        );

        return [
            'markets' => collect(['moneyline', 'spread', 'total'])
                ->mapWithKeys(fn (string $market) => [$market => $this->buildMarketConsensus($latestByUserAndMarket, $market)])
                ->all(),
            'user_bets' => UserBetResource::collection(collect($userBetPaginator->items()))->resolve(),
        ];
    }

    protected function buildMarketConsensus(Collection $bets, string $market): array
    {
        $marketBets = $bets
            ->filter(fn (UserBet $bet) => $this->marketKey($bet->bet_type) === $market)
            ->values();

        $totalPicks = $marketBets->count();
        $validSides = $this->marketSides($market);

        $sides = collect($validSides)->mapWithKeys(function (string $side) use ($marketBets, $totalPicks) {
            $count = $marketBets->where('selection_side', $side)->count();

            return [$side => [
                'count' => $count,
                'percent' => $totalPicks > 0 ? round(($count / $totalPicks) * 100, 1) : 0.0,
            ]];
        })->all();

        $leader = collect($validSides)
            ->map(fn (string $side) => [
                'side' => $side,
                'count' => $sides[$side]['count'],
                'percent' => $sides[$side]['percent'],
            ])
            ->sortByDesc('count')
            ->values()
            ->first();

        return [
            'total_picks' => $totalPicks,
            'leader' => $leader !== null && $leader['count'] > 0 ? $leader : null,
            'sides' => $sides,
        ];
    }

    protected function marketKey(string $betType): string
    {
        return in_array($betType, ['total_over', 'total_under'], true) ? 'total' : $betType;
    }

    /**
     * @return array<int, string>
     */
    protected function marketSides(string $market): array
    {
        return match ($market) {
            'moneyline', 'spread' => ['away', 'home'],
            'total' => ['over', 'under'],
            default => [],
        };
    }
}
