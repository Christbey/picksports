<?php

namespace App\Services\NFL;

use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Descriptive records only: consumes already-loaded, cutoff-bounded team history. */
class NflSituationalRecordService
{
    public function build(object $team, Collection $games, array $markets = []): array
    {
        $definitions = [
            'after_bye' => [281, 'After a bye', 'Provider-rest gap of 13–21 days and a two-week schedule gap after the prior game in the same season.'],
            'before_bye' => [284, 'Before a bye', 'Next game has a provider-rest gap of 13–21 days and a two-week schedule gap in the supplied historical sample.'],
            'opponent_after_bye' => [286, 'Opponent after a bye', 'Opponent has provider-recorded 13–21 rest days, excluding season openers.'],
            'home_favorite' => [325, 'As home favorite', 'Home team favored by a verified archived closing spread; pick’em and neutral sites excluded.'],
            'home_underdog' => [327, 'As home underdog', 'Home team an underdog by a verified archived closing spread; pick’em and neutral sites excluded.'],
            'road_favorite' => [329, 'As road favorite', 'Away team favored by a verified archived closing spread; pick’em and neutral sites excluded.'],
            'road_underdog' => [331, 'As road underdog', 'Away team an underdog by a verified archived closing spread; pick’em and neutral sites excluded.'],
            'rest_advantage_two' => [288, 'At least two extra rest days', 'Provider-recorded own rest minus opponent rest is at least two days.'],
            'rest_advantage_four' => [290, 'At least four extra rest days', 'Provider-recorded own rest minus opponent rest is at least four days.'],
            'rest_disadvantage' => [292, 'Rest disadvantage', 'Provider-recorded own rest is less than opponent rest.'],
            'after_overtime' => [300, 'After overtime', 'The previous adjacent completed game has a recorded final period greater than four.'],
            'after_divisional' => [318, 'Following a divisional game', 'The previous adjacent completed game is provider-marked as a divisional matchup.'],
            'before_divisional' => [319, 'Before a divisional game', 'The next adjacent game in the supplied cutoff-bounded history is provider-marked divisional.'],
            'between_divisional' => [320, 'Between divisional games', 'Both adjacent surrounding games in supplied history are provider-marked divisional.'],
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

        $atsIds = [281 => 282, 284 => 285, 286 => 287, 288 => 289, 290 => 291, 292 => 293, 294 => 295, 296 => 297, 298 => 299, 300 => 301,
            304 => 305, 306 => 307, 308 => 309, 310 => 311, 313 => 316, 315 => 317, 321 => 322,
            323 => 324, 325 => 326, 327 => 328, 329 => 330, 331 => 332, 333 => 334];
        foreach ($records as $id => $record) {
            if (! isset($atsIds[$record['catalog_id']])) {
                continue;
            }
            $records[$id.'_ats'] = [...$record, 'id' => $id.'_ats', 'catalog_id' => $atsIds[$record['catalog_id']],
                'label' => $record['label'].' · closing-line ATS', 'record_type' => 'ats',
                'definition' => $record['definition'].' Reconstructed against the archived closing handicap. W–L–P counts covers, non-covers and pushes; this is not an as-known-at-kickoff wager backtest.',
                'market_evidence' => []];
        }

        $records['after_bye_total'] = [...$records['after_bye'], 'id' => 'after_bye_total', 'catalog_id' => 283,
            'label' => 'After a bye · closing total', 'record_type' => 'totals', 'market_evidence' => [],
            'definition' => 'Combined final score versus the archived closing total after a verified rest/schedule bye. O–U–P counts overs, unders and pushes. Retrospective record, not a pregame betting backtest.'];

        // Keep unusable rows in sequence: they break streaks rather than being silently skipped.
        $rows = $games->filter(fn ($g) => (int) $g->home_team_id === (int) $team->id || (int) $g->away_team_id === (int) $team->id)
            ->map(fn ($g) => ['game' => $g, 'kickoff' => $this->kickoff($g)])
            ->sortBy(fn ($r) => $r['kickoff']?->getTimestamp() ?? strtotime((string) $r['game']->game_date))->values();
        $previous = null;
        $winStreak = $lossStreak = $roadStreak = 0;
        $streakOriginKnown = $roadOriginKnown = false;
        foreach ($rows as $index => $row) {
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
                $market = $markets[$game->id] ?? null;
                $homeHandicap = $market['home_handicap'] ?? null;
                $teamHandicap = $homeHandicap !== null ? ($home ? $homeHandicap : -$homeHandicap) : null;
                if ($teamHandicap !== null && ! $neutral) {
                    $matches += ['home_favorite' => $home && $teamHandicap < 0,
                        'home_underdog' => $home && $teamHandicap > 0,
                        'road_favorite' => ! $home && $teamHandicap < 0,
                        'road_underdog' => ! $home && $teamHandicap > 0];
                }
                $ownRest = $home ? ($game->home_rest ?? null) : ($game->away_rest ?? null);
                $opponentRest = $home ? ($game->away_rest ?? null) : ($game->home_rest ?? null);
                if (is_numeric($ownRest) && is_numeric($opponentRest) && $ownRest >= 4 && $opponentRest >= 4 && $ownRest <= 21 && $opponentRest <= 21 && (int) $game->week > 1) {
                    $matches += ['rest_advantage_two' => $ownRest - $opponentRest >= 2,
                        'rest_advantage_four' => $ownRest - $opponentRest >= 4,
                        'rest_disadvantage' => $ownRest < $opponentRest];
                }
                $next = $rows->get($index + 1);
                $nextDays = $kickoff && $next && $next['kickoff'] ? (int) $kickoff->startOfDay()->diffInDays($next['kickoff']->startOfDay(), false) : null;
                $nextAdjacent = $next && (int) $next['game']->season === (int) $game->season
                    && (int) $next['game']->week === (int) $game->week + 1 && $nextDays >= 4 && $nextDays <= 14;
                $nextDivisional = $nextAdjacent && $next['game']->division_game === true;
                $matches['before_divisional'] = $nextDivisional;
                $nextHome = $next && (int) $next['game']->home_team_id === (int) $team->id;
                $nextRest = $next ? ($nextHome ? $next['game']->home_rest : $next['game']->away_rest) : null;
                $matches['after_bye'] = $sameSeason && $previous['valid'] && (int) $game->week === (int) $previous['game']->week + 2
                    && is_numeric($ownRest) && $ownRest >= 13 && $ownRest <= 21 && $days === (int) $ownRest;
                $matches['before_bye'] = $next && (int) $next['game']->season === (int) $game->season
                    && (int) $next['game']->week === (int) $game->week + 2
                    && is_numeric($nextRest) && $nextRest >= 13 && $nextRest <= 21 && $nextDays === (int) $nextRest;
                $matches['opponent_after_bye'] = (int) $game->week > 1 && is_numeric($opponentRest) && $opponentRest >= 13 && $opponentRest <= 21;
                $matches['after_bye_total'] = $matches['after_bye'] && isset($market['total']);

                if ($adjacent && $previous['valid']) {
                    $priorMargin = $previous['margin'];
                    $matches += [
                        'after_overtime' => is_numeric($previous['game']->period) && (int) $previous['game']->period > 4,
                        'after_divisional' => $previous['game']->division_game === true,
                        'between_divisional' => $previous['game']->division_game === true && $nextDivisional,
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
                    if (isset($records[$id.'_ats'])) {
                        $matches[$id.'_ats'] = $matchesSituation && $teamHandicap !== null;
                    }
                }
                foreach ($matches as $id => $matchesSituation) {
                    if ($matchesSituation) {
                        $record = &$records[$id];
                        $record['sample_size']++;
                        $resultMargin = match ($record['record_type'] ?? null) {
                            'ats' => $margin + $teamHandicap,
                            'totals' => $game->home_score + $game->away_score - $market['total'],
                            default => $margin,
                        };
                        $record['record'][$resultMargin > 0 ? 'wins' : ($resultMargin < 0 ? 'losses' : 'ties')]++;
                        if (in_array($record['record_type'] ?? null, ['ats', 'totals'], true)) {
                            $record['market_evidence'][] = ['game_id' => $game->id, 'team_handicap' => $teamHandicap, ...$market];
                        }
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
            'market_records' => ['ats' => ['status' => $markets === [] ? 'unavailable' : 'retrospective_closing_line_record', 'reason' => $markets === [] ? 'Verified archived closing spreads are not supplied.' : 'Reconstructed closing-line records, including pushes. Archive observation times are retained; synthetic capture times are not evidence of pregame availability.'],
                'totals' => ['status' => $markets === [] ? 'unavailable' : 'retrospective_closing_line_record', 'reason' => $markets === [] ? 'Verified archived closing totals are not supplied.' : 'Reconstructed O–U–P records versus archived closing totals; not an as-known betting backtest.']],
            'limitations' => [
                'Descriptive W-L-T only; sample size is not statistical significance or a validated betting edge. Situations overlap.',
                'History is limited to supplied, cutoff-bounded regular-season games; no sequence crosses a season boundary.',
                'Sequential records require adjacent schedule weeks and known kickoff times; week gaps are excluded because a bye or missing game cannot be distinguished here.',
                'Neutral sites are excluded from home/road records. Thursday and rest use Eastern calendar dates, not UTC weekdays.',
                'Bye, snap counts, travel, stadium and weather conditions require further verified inputs. Rest comparisons use explicit provider rest fields; overtime and division sequences require recorded flags and adjacent games.',
                'Historical ATS uses only explicitly normalized archived nflverse closing lines, not current mutable odds. These retrospective records are not an as-known betting backtest. Missing scores, lines and prior history are excluded, not counted as losses.',
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
