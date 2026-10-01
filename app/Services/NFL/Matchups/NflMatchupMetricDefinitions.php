<?php

namespace App\Services\NFL\Matchups;

/** SQL expressions are fixed source definitions, never request-supplied strings. */
final class NflMatchupMetricDefinitions
{
    public static function splits(): array
    {
        $pass = "play_type = 'pass'";
        $attempt = "play_type = 'pass' AND is_sack = 0";
        $run = "play_type = 'run' AND (is_sack = 0 OR is_sack IS NULL)";
        $success = 'CASE WHEN epa > 0 THEN 1 ELSE 0 END';

        return [
            'first_down_rate' => ['1=1', '1=0', 'first_down', 'first_down', 30],
            'neutral_epa' => ['win_probability BETWEEN 0.2 AND 0.8 AND game_seconds_remaining > 120', 'win_probability IS NULL OR game_seconds_remaining IS NULL', 'epa', 'epa', 8],
            'cpoe' => [$attempt, '1=0', 'cpoe', 'cpoe', 10],
            'completion_rate' => [$attempt, '1=0', 'complete_pass', 'complete_pass', 10],
            'deep_pass_epa' => ["{$attempt} AND air_yards >= 20", '1=0', 'epa', 'epa', 3],
            'intermediate_pass_epa' => ["{$attempt} AND air_yards >= 10 AND air_yards < 20", '1=0', 'epa', 'epa', 3],
            'short_pass_epa' => ["{$attempt} AND air_yards >= 0 AND air_yards < 10", '1=0', 'epa', 'epa', 5],
            'shotgun_pass_epa' => ["{$pass} AND shotgun = 1", "{$pass} AND shotgun IS NULL", 'epa', 'epa', 5],
            'under_center_pass_success' => ["{$pass} AND shotgun = 0", "{$pass} AND shotgun IS NULL", 'epa', $success, 3],
            'pass_td_rate' => [$pass, '1=0', 'pass_touchdown', 'pass_touchdown', 15],
            'interception_rate' => [$attempt, '1=0', 'is_interception', 'is_interception', 15],
            'pass_rate' => ['1=1', '1=0', 'play_type', "CASE WHEN {$pass} THEN 1 ELSE 0 END", 30],
            'run_rate' => ['1=1', '1=0', 'play_type', "CASE WHEN {$run} THEN 1 ELSE 0 END", 30],
            'pass_oe' => ['1=1', '1=0', 'pass_oe', 'pass_oe', 30],
            'rush_stuff_rate' => [$run, '1=0', 'yards_gained', 'CASE WHEN yards_gained <= 0 THEN 1 ELSE 0 END', 8],
            'goal_line_rush_epa' => ["{$run} AND yardline_100 BETWEEN 0 AND 5", "{$run} AND yardline_100 IS NULL", 'epa', 'epa', 2],
            'early_run_rate' => ['down IN (1, 2)', 'down IS NULL', 'play_type', "CASE WHEN {$run} THEN 1 ELSE 0 END", 15],
            'sack_rate' => [$pass, '1=0', 'is_sack', 'is_sack', 15],
        ];
    }

    public static function lowerOffenseIsBetter(string $metric): bool
    {
        return in_array($metric, ['interception_rate', 'rush_stuff_rate', 'sack_rate'], true);
    }

    public static function definition(string $metric): ?string
    {
        return match ($metric) {
            'opponent_adjusted_epa' => 'Play-weighted EPA minus each opponent’s other-game EPA allowed (offense) or produced (defense). The game being adjusted is excluded from the baseline. Requires at least two other complete games for every opponent. This is a one-pass adjustment, not DVOA.',
            'epa_trend_3', 'pass_epa_trend_3', 'rush_epa_trend_3' => 'Three consecutive improvements or declines in per-game EPA, requiring four complete games in chronological order. Offense improves upward and defense improves downward; any reversal or flat change does not qualify.',
            'epa_trend_5', 'pass_epa_trend_5', 'rush_epa_trend_5' => 'Ordinary least-squares slope of per-game EPA across the five latest complete games. A positive slope improves offense; a negative slope improves defense. Fewer than five games is insufficient.',
            'first_down_rate' => 'Share of eligible scrimmage plays earning a provider-recorded first down; defense is first downs allowed.',
            'neutral_epa' => 'EPA on plays with pre-play possession win probability between 20% and 80% and more than two minutes remaining. This is the explicit neutral-script definition.',
            'cpoe' => 'Provider completion percentage over expectation, averaged over nonsack pass attempts with supplied CPOE; no estimate is substituted.',
            'completion_rate' => 'Completions per nonsack pass attempt; lower completion rate allowed ranks better on defense.',
            'deep_pass_epa' => 'EPA on nonsack passes with at least 20 air yards. Untargeted/unknown-depth passes are outside this split.',
            'intermediate_pass_epa' => 'EPA on nonsack passes with 10 to less than 20 air yards.',
            'short_pass_epa' => 'EPA on nonsack passes with 0 to less than 10 air yards; screens are not inferred from short air yards.',
            'shotgun_pass_epa' => 'EPA on provider-marked shotgun pass plays, including sacks.',
            'under_center_pass_success' => 'Share of provider-marked non-shotgun pass plays with positive EPA, including sacks.',
            'pass_td_rate' => 'Passing touchdowns per pass play including sacks; defensive rate is touchdowns allowed.',
            'interception_rate' => 'Interceptions per nonsack pass attempt. Lower offense and higher defensive interception rate rank better.',
            'pass_rate' => 'Share of eligible scrimmage plays classified as passes, including sacks. Pass-heavy means top-10 league pass rate.',
            'run_rate' => 'Share of eligible scrimmage plays classified as runs. Run-heavy means top-10 league run rate.',
            'pass_oe' => 'Average provider pass rate over expectation (percentage points), using supplied values rather than deriving a proxy.',
            'rush_stuff_rate' => 'Share of run plays gaining zero or negative yards. Lower offensive stuff rate and higher defensive stuff rate rank better.',
            'goal_line_rush_epa' => 'Rushing EPA on plays starting at or inside the opponent five-yard line.',
            'early_run_rate' => 'Share of first- and second-down scrimmage plays classified as runs.',
            'sack_rate' => 'Sacks per pass play, including sacks in the denominator. Lower offensive sacks allowed and higher defensive sack rate rank better; not a pressure or blocking-win-rate proxy.',
            'drive_success_rate' => 'Share of complete drives containing a scrimmage play that end in an offensive touchdown or made field goal. This explicit scoring-drive definition is not a first-down series success metric.',
            default => null,
        };
    }
}
