<?php

namespace App\Support;

use App\Models\CFB\Game;
use Carbon\CarbonInterface;

class CfbPostseasonRoundResolver
{
    public const BOWL_GAMES = 1;

    public const CFP_FIRST_ROUND = 2;

    public const CFP_QUARTERFINALS = 3;

    public const CFP_SEMIFINALS = 4;

    public const NATIONAL_CHAMPIONSHIP = 5;

    /**
     * @param  array<string, mixed>  $eventData
     */
    public function resolveFromEspnEvent(array $eventData): ?int
    {
        if ((int) data_get($eventData, 'season.type', 2) !== (int) config('cfb.season.types.postseason')) {
            return null;
        }

        $competition = data_get($eventData, 'competitions.0', []);
        $text = $this->buildSearchText([
            data_get($competition, 'notes.0.headline'),
            data_get($eventData, 'headline'),
            data_get($eventData, 'name'),
            data_get($eventData, 'shortName'),
            data_get($competition, 'type.abbreviation'),
        ]);

        return $this->resolveFromText($text);
    }

    public function resolveFromStoredGame(Game $game): ?int
    {
        if ((int) $game->season_type !== (int) config('cfb.season.types.postseason')) {
            return null;
        }

        $text = $this->buildSearchText([
            $game->name,
            $game->short_name,
            $this->dateHint($game->game_date),
        ]);

        return $this->resolveFromText($text);
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    protected function buildSearchText(array $parts): string
    {
        $text = implode(' ', array_filter($parts));
        $text = mb_strtolower($text);

        return preg_replace('/\s+/', ' ', $text) ?? '';
    }

    protected function resolveFromText(string $text): int
    {
        if ($text === '') {
            return self::BOWL_GAMES;
        }

        if (str_contains($text, 'national championship') || str_contains($text, 'championship game')) {
            return self::NATIONAL_CHAMPIONSHIP;
        }

        if (str_contains($text, 'semifinal')) {
            return self::CFP_SEMIFINALS;
        }

        if (str_contains($text, 'quarterfinal')) {
            return self::CFP_QUARTERFINALS;
        }

        if (str_contains($text, 'first round')) {
            return self::CFP_FIRST_ROUND;
        }

        return self::BOWL_GAMES;
    }

    protected function dateHint(?CarbonInterface $gameDate): ?string
    {
        return $gameDate?->format('F Y');
    }
}
