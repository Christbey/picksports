<?php

namespace App\Services\CFB\Predictions;

use App\Models\MarketQuote;
use Carbon\CarbonInterface;

/** The closing line is the last quote actually stored before kickoff, not a consensus or later revision. */
class CfbStoredPregameQuote
{
    public function latest(int $gameId, string $marketKey, string $side, CarbonInterface $kickoff, ?CarbonInterface $asOf = null): ?MarketQuote
    {
        // Database timestamps use the application timezone, including UTC callers.
        $kickoff = $kickoff->copy()->setTimezone(config('app.timezone'));
        $asOf = $asOf?->copy()->setTimezone(config('app.timezone'));

        return MarketQuote::query()->where('sport', 'cfb')->where('game_table', 'cfb_games')
            ->where('game_id', $gameId)->where('market_key', $marketKey)->where('side', $side)
            ->where('is_pregame', true)->where('captured_at', '<', $kickoff)
            ->where('created_at', '<', $kickoff)
            ->when($asOf, fn ($query) => $query->where('captured_at', '<=', $asOf)->where('created_at', '<=', $asOf))
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }
}
