<?php

namespace App\Services\NFL;

use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Descriptive records only: consumes already-loaded, cutoff-bounded team history. */
class NflSituationalRecordService
{
    public function build(object $team, Collection $games): array
    {
        $definitions = [
            'home' => [321, 'Home record', 'Designated home games, excluding neutral sites.'],
            'road' => [323, 'Road record', 'Designated away games, excluding neutral sites.'],
            'thursday' => [294, 'Thursday record', 'Kickoff falls on Thursday in America/New_York.'],
            'after_win' => [null, 'After a win', 'Previous completed game in the same regular season was a win.'],
            'after_loss' => [null, 'After a loss', 'Previous completed game in the same regular season was a loss.'],
            'after_blowout_win' => [304, 'After a blowout win', 'Previous margin was at least +21.'],
            'after_blowout_loss' => [306, 'After a blowout loss', 'Previous margin was at most -21.'],
            'after_one_score_win' => [308, 'After a one-score win', 'Previous margin was +1 through +8.'],
            'after_one_score_loss' => [310, 'After a one-score loss', 'Previous margin was -8 through -1.'],
            'after_two_wins' => [312, 'After exactly two straight wins', 'Entering with exactly two consecutive wins; longer streaks are separate.'],
            'after_three_wins' => [313, 'After three or more straight wins', 'Entering with at least three consecutive wins.'],
            'after_two_losses' => [314, 'After exactly two straight losses', 'Entering with exactly two consecutive losses; longer streaks are separate.'],
            'after_three_losses' => [315, 'After three or more straight losses', 'Entering with at least three consecutive losses.'],
            'second_road' => [333, 'Second consecutive road game', 'Exactly the second consecutive away game, excluding neutral sites.'],
            'third_road' => [335, 'Third consecutive road game', 'Exactly the third consecutive away game, excluding neutral sites.'],
            'short_rest' => [null, 'On short rest', 'Four through six Eastern calendar days between kickoffs in the same season.'],
            'extended_rest' => [null, 'On extended rest', 'Eight through fourteen Eastern calendar days between kickoffs; not a verified bye.'],
            'sunday_after_monday' => [296, 'Sunday after Monday game', 'Sunday kickoff six Eastern calendar days after a Monday game.'],
            'after_thursday' => [298, 'After Thursday mini-bye', 'Sunday kickoff ten Eastern calendar days after a Thursday game.'],
        ];
        $records = [];
        foreach ($definitions as $id => [$catalogId, $label, $definition]) {
            $records[$id] = [
                'id' => $id, 'catalog_id' => $catalogId, 'label' => $label, 'definition' => $definition,
                'minimum_sample' => 3, 'sample_size' => 0, 'record' => ['wins' => 0, 'losses' => 0, 'ties' => 0],
                'status' => 'insufficient_data', 'from_date' => null, 'through_date' => null, 'game_ids' => [],
            ];
        }

        // Keep unusable rows in sequence: they break streaks rather than being silently skipped.
        $rows = $games->filter(fn ($g) => (int) $g->home_team_id === (int) $team->id || (int) $g->away_team_id === (int) $team->id)
            ->map(fn ($g) => ['game' => $g, 'kickoff' => $this->kickoff($g)])
            ->sortBy(fn ($r) => $r['kickoff']?->getTimestamp() ?? strtotime((string) $r['game']->game_date))->values();
        $previous = null;
        $winStreak = $lossStreak = $roadStreak = 0;
        $streakOriginKnown = $roadOriginKnown = false;
        foreach ($rows as $row) {
            $game = $row['game'];
            $kickoff = $row['kickoff'];
            $valid = $this->validResult($game);
            $home = (int) $game->home_team_id === (int) $team->id;
            $neutral = (bool) ($game->neutral_site ?? false);
            $road = ! $home && ! $neutral;
            $margin = $valid ? (int) ($home ? $game->home_score - $game->away_score : $game->away_score - $game->home_score) : null;
            $sameSeason = $previous && (string) $previous['game']->season === (string) $game->season;
            $days = $sameSeason && $kickoff && $previous['kickoff']
                ? (int) $previous['kickoff']->startOfDay()->diffInDays($kickoff->startOfDay(), false) : null;
            // A gap beyond a normal bye or a skipped schedule week is not proof of adjacent games.
            $adjacent = $sameSeason && $days !== null && $days >= 4 && $days <= 14
                && is_numeric($previous['game']->week) && is_numeric($game->week)
                && (int) $game->week === (int) $previous['game']->week + 1;
            if (! $adjacent || ! $previous['valid']) {
                $winStreak = $lossStreak = $roadStreak = 0;
                $streakOriginKnown = $roadOriginKnown = (int) $game->week === 1;
            }
            if ($valid) {
                $matches = ['home' => $home && ! $neutral, 'road' => $road, 'thursday' => $kickoff?->isThursday() ?? false];
                if ($adjacent && $previous['valid']) {
                    $priorMargin = $previous['margin'];
                    $matches += [
                        'after_win' => $priorMargin > 0, 'after_loss' => $priorMargin < 0,
                        'after_blowout_win' => $priorMargin >= 21, 'after_blowout_loss' => $priorMargin <= -21,
                        'after_one_score_win' => $priorMargin >= 1 && $priorMargin <= 8,
                        'after_one_score_loss' => $priorMargin >= -8 && $priorMargin <= -1,
                        'after_two_wins' => $streakOriginKnown && $winStreak === 2,
                        'after_three_wins' => $winStreak >= 3,
                        'after_two_losses' => $streakOriginKnown && $lossStreak === 2,
                        'after_three_losses' => $lossStreak >= 3,
                        'second_road' => $roadOriginKnown && $road && $roadStreak === 1,
                        'third_road' => $roadOriginKnown && $road && $roadStreak === 2,
                        'short_rest' => $days >= 4 && $days <= 6,
                        'extended_rest' => $days >= 8 && $days <= 14,
                        'sunday_after_monday' => $days === 6 && $kickoff->isSunday() && $previous['kickoff']->isMonday(),
                        'after_thursday' => $days === 10 && $kickoff->isSunday() && $previous['kickoff']->isThursday(),
                    ];
                }
                foreach ($matches as $id => $matchesSituation) {
                    if ($matchesSituation) {
                        $record = &$records[$id];
                        $record['sample_size']++;
                        $record['record'][$margin > 0 ? 'wins' : ($margin < 0 ? 'losses' : 'ties')]++;
                        $date = $kickoff?->toDateString() ?? substr((string) $game->game_date, 0, 10);
                        $record['from_date'] ??= $date;
                        $record['through_date'] = $date;
                        $record['game_ids'][] = $game->id;
                        $record['status'] = $record['sample_size'] >= 3 ? 'descriptive_record' : 'insufficient_data';
                        unset($record);
                    }
                }
            }
            if (! $valid || $margin === 0 || ($margin > 0 && $lossStreak > 0) || ($margin < 0 && $winStreak > 0)) {
                $streakOriginKnown = $valid;
            }
            $winStreak = $valid && $margin > 0 ? $winStreak + 1 : 0;
            $lossStreak = $valid && $margin < 0 ? $lossStreak + 1 : 0;
            if (! $road) {
                $roadOriginKnown = true;
            }
            $roadStreak = $road ? $roadStreak + 1 : 0;
            $previous = $row + ['margin' => $margin, 'valid' => $valid];
        }

        return [
            'version' => 'nfl-situational-records-v1', 'source' => 'nfl_games', 'timezone' => 'America/New_York',
            'records' => array_values($records),
            'market_records' => ['ats' => ['status' => 'unavailable', 'reason' => 'Verified immutable historical pregame spread snapshots are not supplied.'],
                'totals' => ['status' => 'unavailable', 'reason' => 'Verified immutable historical pregame total snapshots are not supplied.']],
            'limitations' => [
                'Descriptive W-L-T only; sample size is not statistical significance or a validated betting edge. Situations overlap.',
                'History is limited to supplied, cutoff-bounded regular-season games; no sequence crosses a season boundary.',
                'Sequential records require adjacent schedule weeks and known kickoff times; week gaps are excluded because a bye or missing game cannot be distinguished here.',
                'Neutral sites are excluded from home/road records. Thursday and rest use Eastern calendar dates, not UTC weekdays.',
                'Bye, opponent-rest, overtime, snap counts, division sequences, travel, stadium and weather conditions are unavailable without verified inputs.',
                'Historical market odds are not reconstructed from current mutable odds. Missing scores and prior history are not counted as losses.',
            ],
        ];
    }

    private function validResult(object $game): bool
    {
        return $game->status === 'STATUS_FINAL'
            && in_array((string) $game->season_type, ['2', 'regular', 'regular_season'], true)
            && is_numeric($game->home_score) && is_numeric($game->away_score)
            && $game->home_score >= 0 && $game->away_score >= 0;
    }

    private function kickoff(object $game): ?CarbonImmutable
    {
        // Date-only midnight is not enough evidence to infer a weekday in Eastern time.
        $date = $game->game_date;
        $time = $game->game_time;
        try {
            if (! $date || (! $time && CarbonImmutable::parse($date)->format('H:i:s') === '00:00:00')) {
                return null;
            }

            return app(SportsDateWindowService::class)->gameDateTimeUtc($date, $time)?->setTimezone('America/New_York');
        } catch (\Throwable) {
            return null;
        }
    }
}
