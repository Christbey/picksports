<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use App\Support\NFL\NflGameStart;

class NflPropGameTime
{
    public const CLOCK_METADATA_BYTES = 65_536;

    public function __construct(private SportsDateWindowService $dates) {}

    /** The board and its cards share one explicit, DST-aware kickoff contract. */
    public function forGame(Game $game): array
    {
        $attributes = $game->getAttributes();
        $rawMetadata = $game->getRawOriginal('odds_data');
        $overLimit = is_string($rawMetadata) && strlen($rawMetadata) > self::CLOCK_METADATA_BYTES;
        $metadataUnavailable = ((bool) ($attributes['read_clock_metadata_unavailable'] ?? false) || $overLimit)
            && is_string($game->nflverse_game_id) && $game->nflverse_game_id !== ''
            && NflGameStart::possibleMissingImportedClock($game->getRawOriginal('game_time'));
        $clockSource = $game;
        if ($overLimit && ! $metadataUnavailable) {
            // This clock cannot be the exact missing-clock carrier; avoid decoding unrelated oversized context.
            $clockSource = clone $game;
            $clockSource->setRawAttributes([...$game->getAttributes(), 'odds_data' => null], true);
        }
        $kickoff = $metadataUnavailable ? null : NflGameStart::for($clockSource);
        $storedDate = substr((string) ($game->getRawOriginal('game_date') ?? ($game->getAttributes()['game_date'] ?? null)), 0, 10);
        $validDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $storedDate, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        $local = $kickoff?->setTimezone($this->dates->timezone());

        return [
            'date' => $local?->toDateString() ?? ($validDate ? $storedDate : null),
            'starts_at' => $kickoff?->toIso8601String(),
            'timezone' => $this->dates->timezone(),
            'time_label' => $local?->format('g:i A T') ?? 'Time TBD',
            'kickoff_label' => $local?->format('D, M j, g:i A T') ?? 'Time TBD',
        ];
    }
}
