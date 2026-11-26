<?php

namespace App\Services\NFL\Predictions;

use App\Support\Odds\MarketSpread;

/** Pure quote parsing. Returned spread is home margin, not sportsbook home line. */
final class NflMarketQuoteLines
{
    /** @return array{0:?float,1:?float} */
    public function extract(array $odds): array
    {
        $selected = $this->selected($odds);

        return [isset($selected['spreads']) ? MarketSpread::bookmakerHomeLineToHomeMargin($selected['spreads']['line']) : null,
            $selected['totals']['line'] ?? null];
    }

    /** Exact raw-book selections, used to verify freshness against frozen paired quotes. */
    public function selected(array $odds): array
    {
        $home = $odds['home_team'] ?? null;
        $spread = $total = null;
        $selected = [];
        $books = $odds['bookmakers'] ?? [];
        foreach (is_array($books) ? $books : [] as $book) {
            $markets = is_array($book) ? ($book['markets'] ?? []) : [];
            foreach (is_array($markets) ? $markets : [] as $market) {
                if (! is_array($market)) {
                    continue;
                }
                $outcomes = $market['outcomes'] ?? [];
                foreach (is_array($outcomes) ? $outcomes : [] as $outcome) {
                    if (! is_array($outcome) || ! is_numeric($outcome['point'] ?? null)
                        || ! is_finite((float) $outcome['point'])) {
                        continue;
                    }
                    $point = (float) $outcome['point'];
                    if (($market['key'] ?? null) === 'spreads' && $spread === null
                        && is_string($home) && trim($home) !== '' && ($outcome['name'] ?? null) === $home) {
                        $spread = $point;
                        $selected['spreads'] = ['bookmaker_key' => $book['key'] ?? null, 'bookmaker_title' => $book['title'] ?? null, 'line' => $point];
                    }
                    if (($market['key'] ?? null) === 'totals' && $total === null && $point > 0) {
                        $total = $point;
                        $selected['totals'] = ['bookmaker_key' => $book['key'] ?? null, 'bookmaker_title' => $book['title'] ?? null, 'line' => $point];
                    }
                }
            }
            if ($spread !== null && $total !== null) {
                break;
            }
        }

        return $selected;
    }
}
