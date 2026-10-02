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
            'qb_designed_run_rate' => ["{$run} AND run_roster.roster_position IS NOT NULL AND qb_scramble IS NOT NULL", "{$run} AND (run_roster.roster_position IS NULL OR qb_scramble IS NULL)", 'qb_scramble', "CASE WHEN run_roster.roster_position = 'QB' AND qb_scramble = 0 THEN 1 ELSE 0 END", 8],
            'qb_run_epa' => ["{$run} AND run_roster.roster_position = 'QB'", "{$run} AND run_roster.roster_position IS NULL", 'epa', 'epa', 3],
            'qb_run_explosive_rate' => ["{$run} AND run_roster.roster_position = 'QB'", "{$run} AND run_roster.roster_position IS NULL", 'yards_gained', 'CASE WHEN yards_gained >= 10 THEN 1 ELSE 0 END', 3],
            'rb_run_explosive_rate' => ["{$run} AND run_roster.roster_position = 'RB'", "{$run} AND run_roster.roster_position IS NULL", 'yards_gained', 'CASE WHEN yards_gained >= 10 THEN 1 ELSE 0 END', 8],
            'scramble_epa' => ["{$run} AND qb_scramble = 1", "{$run} AND qb_scramble IS NULL", 'epa', 'epa', 3],
            'wr_target_epa' => ["{$attempt} AND catch_roster.roster_position = 'WR'", "{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NULL", 'epa', 'epa', 8],
            'te_target_share' => ["{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NOT NULL", "{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NULL", 'receiver_player_id', "CASE WHEN catch_roster.roster_position = 'TE' THEN 1 ELSE 0 END", 15],
            'rb_target_share' => ["{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NOT NULL", "{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NULL", 'receiver_player_id', "CASE WHEN catch_roster.roster_position = 'RB' THEN 1 ELSE 0 END", 15],
            'te_target_epa' => ["{$attempt} AND catch_roster.roster_position = 'TE'", "{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NULL", 'epa', 'epa', 5],
            'wr_deep_target_rate' => ["{$attempt} AND catch_roster.roster_position = 'WR'", "{$attempt} AND receiver_player_id IS NOT NULL AND catch_roster.roster_position IS NULL", 'air_yards', 'CASE WHEN air_yards >= 20 THEN 1 ELSE 0 END', 8],
            'play_action_epa' => ["{$pass} AND ftn_is_play_action = 1", "{$pass} AND ftn_is_play_action IS NULL", 'epa', 'epa', 5],
            'screen_pass_epa' => ["{$pass} AND ftn_is_screen_pass = 1", "{$pass} AND ftn_is_screen_pass IS NULL", 'epa', 'epa', 2],
            'rpo_epa' => ["{$pass} AND ftn_is_rpo = 1", "{$pass} AND ftn_is_rpo IS NULL", 'epa', 'epa', 2],
            'blitz_rate' => [$pass, '1=0', 'ftn_n_blitzers', 'CASE WHEN ftn_n_blitzers > 0 THEN 1 ELSE 0 END', 15],
            'motion_rate' => ['1=1', '1=0', 'ftn_is_motion', 'ftn_is_motion', 30],
            'motion_epa' => ['ftn_is_motion = 1', 'ftn_is_motion IS NULL', 'epa', 'epa', 5],
            'defensive_box' => [$run, '1=0', 'NULLIF(ftn_n_defense_box, 0)', 'ftn_n_defense_box', 8],
            'first_down_rate' => ['1=1', '1=0', 'first_down', 'first_down', 30],
            'neutral_epa' => ['win_probability BETWEEN 0.2 AND 0.8 AND game_seconds_remaining > 120', 'win_probability IS NULL OR game_seconds_remaining IS NULL', 'epa', 'epa', 8],
            'air_yards_per_attempt' => [$attempt, '1=0', 'air_yards', 'air_yards', 10],
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
            'wr_target_epa' => 'Passing EPA on identified WR targets, including incompletions and excluding sacks. Sixteen pooled targets, unambiguous season/team roster mappings and complete per-game coverage are required. This describes the WR unit, not individual coverage assignments.',
            'te_target_share', 'rb_target_share' => 'Share of identified, roster-mapped nonsack pass targets going to the named TE or RB position. Requires thirty pooled identified targets and 90% position mapping coverage per complete game. Unidentified throws are excluded; no defender assignment is inferred.',
            'wr_target_concentration' => 'The largest individual WR target count divided by all identified, position-mapped pass targets in the selected season before kickoff. Requires thirty pooled identified targets and complete league coverage. Tied leading receivers are retained together; this does not designate a depth-chart WR1.',
            'secondary_out', 'safety_out', 'lb_out', 'cb1_out' => 'Count of distinct projected depth-rank-one players in the named defensive position group with an explicit current unavailable designation. Requires a fresh game-linked chart and timestamped injury evidence. Questionable/Doubtful or missing statuses never count as absences. A lead CB requires exactly one identified starting corner; multiple CB slots are ambiguous. These are team personnel conditions, not claims about coverage assignments.',
            'qb_recent_change' => 'The latest game-linked chart names the selected QB at rank one and differs from the immediately preceding same-season team chart observed within the last seven days. Both observations must predate the evaluation cutoff and identify one QB. This compares two saved projections, not a confirmed starter change or proof that an unchanged pair covers every intervening day.',
            'qb_designed_run_rate' => 'Share of classified runs credited to a roster-verified QB and explicitly marked non-scramble. Kneels and no-play rows are excluded by play type. This describes provider non-scramble QB runs, not a charted run concept. Requires 16 pooled runs and 90% position/scramble classification per complete game.',
            'qb_run_epa' => 'EPA on runs credited to a roster-verified QB, including scrambles and excluding kneels by play type. At least six pooled QB runs across two complete games; unknown rusher positions count against coverage.',
            'qb_run_explosive_rate' => 'Share of roster-verified QB runs gaining at least ten yards, including scrambles. Requires six pooled QB runs and complete game coverage; not all-team rushing explosives.',
            'rb_run_explosive_rate' => 'Share of roster-verified RB runs gaining at least ten yards; excludes QB and FB runs. Requires sixteen pooled RB runs and complete coverage. Describes the RB unit, not a selected individual back.',
            'scramble_epa' => 'EPA on provider-flagged quarterback scrambles. Requires six pooled scrambles and complete per-game EPA/classification coverage; non-scramble runs are excluded.',
            'qb_scramble_rate' => 'Game-selected QB scramble share of passing plays (including sacks) plus scrambles. Requires two complete passing appearances, 15 measured passing plays per appearance, 90% run-player and scramble-flag coverage in every appearance, and at least 24 qualified QBs. Uses that QB’s rusher ID, never team rushing totals.',
            'te_target_epa' => 'Passing EPA on identified targets to roster-verified TEs, including incompletions and excluding sacks. Ten pooled targets and 90% EPA/position coverage in every complete game are required. Unidentified throws are excluded; this describes the TE receiving unit and EPA allowed to that position, not individual coverage assignments.',
            'wr_deep_target_rate' => 'Share of identified WR targets thrown at least twenty air yards. Requires sixteen pooled WR targets and 90% depth/position coverage in every complete game. Describes WR-unit deep-target frequency, not receiver speed or separation.',
            'te1_out' => 'A unique game-linked depth-rank-one TE has an explicit current unavailable designation. Multiple starter slots, uncertain injury statuses and missing evidence remain unknown. The opposing defense is ranked by EPA allowed on identified TE targets.',
            'multiple_wr_out' => 'At least two distinct depth-rank-one WRs in the game-linked chart have explicit current unavailable designations. Unknown statuses cannot be counted as absences; fewer than two known absences requires complete status evidence for a negative result. No historical starter or receiver ordering is inferred.',
            'backup_qb' => 'The game-selected quarterback maps uniquely through the target-season team roster to a depth rank greater than one in a fresh game-linked QB chart containing one identified starter. This describes the saved chart, not confirmed participation or a career backup designation. A quarterback already promoted to rank one is not classified as a backup. Missing, stale, future-dated or ambiguous evidence stays unknown; the selected QB must pass the availability check. Defense uses overall EPA allowed.',
            'rookie_qb' => 'The game-selected quarterback has exactly zero years_exp on an unambiguous NFLverse QB roster record for the target season and team. Missing or conflicting experience is unknown, not rookie. Identity and game-scoped unavailability checks still apply. No passing-appearance minimum is imposed on rookie status. Road excludes neutral venues; top defense means top-10 overall EPA allowed, and blitz-heavy means the ten highest FTN-charted blitz rates.',
            'qb_pass_epa_trend_3' => 'Selected quarterback passing EPA per game, including sacks, must change in the same strict direction on all three transitions across the latest four appearances in the selected season. Every appearance must pass the existing 15-play, 90% EPA and passer-identity coverage checks. Flat or mixed changes do not qualify. The value is the smallest signed improvement or decline; games have equal weight. This is a within-player trend, not a league QB ranking. Opposing pass-defense EPA allowed uses the latest four team games; increasing EPA allowed means decline.',
            'qb_deep_epa' => 'Selected quarterback EPA on nonsack pass attempts with at least 20 air yards. Requires two complete passing appearances, ten pooled deep attempts, 90% sack/depth classification coverage and 90% deep EPA coverage in every appearance, and at least 24 qualified passers. Sacks are excluded from deep attempts.',
            'qb_rpo_epa' => 'Selected quarterback EPA on pass plays explicitly charted by FTN as run-pass options (RPOs), including sacks. Rushing branches are excluded. Requires two complete passing appearances, ten pooled RPO pass plays, 90% RPO classification and 90% split EPA coverage in every appearance, and at least 24 qualified passers. Missing flags are not inferred from play text.',
            'qb_play_action_epa' => 'Selected quarterback EPA on pass plays explicitly charted by FTN as play action, including sacks. Requires two complete passing appearances, ten pooled play-action plays, 90% charting and 90% split EPA coverage in every appearance, and at least 24 qualified passers.',
            'epa_stddev' => 'Sample standard deviation of per-game EPA means across the explicitly selected season, with equal weight per game and at least three complete games for every league team. Offense uses EPA produced and defense EPA allowed. Rank 1 is the lowest variability on both sides; consistent means the ten lowest and high variance the ten highest. Ties crossing a band boundary are excluded. Variability is not a measure of quality, a predictive edge, or a confidence adjustment.',
            'ol_changed', 'ol_changed_two' => 'Number of LT/LG/C/RG/RT projected position assignments changed from the previous adjacent regular-season week. Requires five distinct players in both charts, a saved game link for the target, and a chart observed before the prior game. These are projected lineups, not observed starts.',
            'ol_same_four' => 'The same five projected LT/LG/C/RG/RT assignments across the target game and the three immediately preceding regular-season weeks. Every historical chart must predate its game. Missing weeks or ambiguous positions remain unavailable; projected continuity is not proof of participation.',
            'wr1_out' => 'The game-linked depth chart must contain exactly one identified depth-rank-one WR. Multiple starting WR slots are ambiguous; no WR1 is inferred from slot order, targets, names or depth-rank-two backups. That receiver must have an explicit current Out, Inactive, reserve or suspension designation. Missing or conflicting reports and Questionable/Doubtful statuses are unknown. A complete fresh injury report without that player supports only no unavailable designation. Defense quality is ranked by passing EPA allowed including sacks, not cornerback or coverage grades.',
            'rb1_out' => 'The unique depth-rank-one RB in the game-linked chart has an explicit current Out, Inactive, reserve or suspension status. Questionable, Doubtful, missing or conflicting injury evidence is unknown. Absence from a complete, fresh ESPN team injury report means no unavailable designation, not proof of health. Weak run defense means bottom-10 rushing EPA allowed.',
            'backup_center' => 'The target projected center differs from the previous week’s projected starter, was listed as a backup center in that pregame chart, and that prior starter is explicitly unavailable. Current projected status is not confirmation of participation.',
            'blitz_rate' => 'Share of pass plays with at least one FTN-charted blitzer. High blitz frequency is the highest ten usage rates (the bottom rank band under the defensive lower-value ordering), not a claim of defensive quality.',
            'qb_blitz_epa' => 'Selected quarterback passing EPA on FTN-charted blitzes, including sacks. At least two complete passing appearances, ten charted blitz plays pooled, 90% per-appearance charting coverage, and 24 qualified passers are required. High-blitz defenses are the ten highest charted blitz rates.',
            'home_pass_epa', 'road_pass_epa' => 'Passing EPA in the named home/road subset, excluding neutral sites. At least two qualifying games in that subset and complete coverage of every preceding game in the subset for all 32 teams. Applied only when the target offense plays in the named venue role.',
            'air_yards_per_attempt' => 'Mean supplied air yards on nonsack pass attempts. Requires 90% air-yard coverage in each complete game; missing depth is never zero.',
            'qb_pass_epa' => 'Passing EPA for the game-selected quarterback, including sacks and excluding scrambles. Rank among qualified quarterbacks with at least two games of 15 pass plays and 90% EPA coverage in every appearance; at least 24 qualified quarterbacks required. Identity is sourced from the game or its linked pregame depth chart, never team passing totals.',
            'opponent_adjusted_epa' => 'Play-weighted EPA minus each opponent’s other-game EPA allowed (offense) or produced (defense). The game being adjusted is excluded from the baseline. Requires at least two other complete games for every opponent. This is a one-pass adjustment, not DVOA.',
            'epa_trend_3', 'pass_epa_trend_3', 'rush_epa_trend_3' => 'Three consecutive improvements or declines in per-game EPA, requiring four complete games in chronological order. Offense improves upward and defense improves downward; any reversal or flat change does not qualify.',
            'epa_trend_5', 'pass_epa_trend_5', 'rush_epa_trend_5' => 'Ordinary least-squares slope of per-game EPA across the five latest complete games. A positive slope improves offense; a negative slope improves defense. Fewer than five games is insufficient.',
            'play_action_epa' => 'EPA on pass plays explicitly charted by FTN as play action. Missing flags are not inferred from play text.',
            'screen_pass_epa' => 'EPA on pass plays explicitly charted by FTN as screens. Short throws are not automatically screens.',
            'rpo_epa' => 'EPA on pass plays explicitly charted by FTN as RPOs. Teams without enough charted RPO opportunities cannot receive an RPO efficiency rank.',
            'motion_rate' => 'Share of scrimmage plays with FTN-charted motion; motion-heavy means top-10 usage.',
            'motion_epa' => 'EPA on scrimmage plays with FTN-charted motion; defensive value is EPA allowed against motion.',
            'defensive_box' => 'Average FTN-charted defenders in the box on run plays. Light boxes rank first and heavy boxes rank last; this measures alignment, not defensive quality. Zero/unknown counts are excluded.',
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
