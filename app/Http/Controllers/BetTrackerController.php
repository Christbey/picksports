<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserBetResource;
use App\Models\UserBet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BetTrackerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $bets = UserBet::where('user_id', $userId)
            ->with('prediction')
            ->orderBy('placed_at', 'desc')
            ->paginate(20);

        $statistics = $this->calculateStatistics($userId);

        return response()->json([
            'bets' => UserBetResource::collection($bets)->response()->getData(),
            'statistics' => $statistics,
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
            'notes' => 'nullable|string|max:1000',
            'placed_at' => 'nullable|date',
        ]);

        $bet = UserBet::create([
            'user_id' => $request->user()->id,
            'prediction_id' => $validated['prediction_id'] ?? null,
            'prediction_type' => $validated['prediction_type'] ?? null,
            'bet_amount' => $validated['bet_amount'],
            'odds' => $validated['odds'],
            'bet_type' => $validated['bet_type'],
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
            'result' => 'sometimes|in:pending,won,lost,push',
            'profit_loss' => 'sometimes|numeric',
            'notes' => 'nullable|string|max:1000',
            'placed_at' => 'sometimes|date',
            'settled_at' => 'nullable|date',
        ]);

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
}
