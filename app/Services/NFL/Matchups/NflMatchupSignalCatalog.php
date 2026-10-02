<?php

namespace App\Services\NFL\Matchups;

final class NflMatchupSignalCatalog
{
    public const VERSION = '2026-10-01.27';

    /** Rules are descriptive; overlapping ranks must never be added as independent evidence. */
    public function rules(): array
    {
        $rules = [];
        foreach ([1 => ['epa', 5], 5 => ['epa', 10], 11 => ['success_rate', 10], 15 => ['points_per_drive', 10], 19 => ['yards_per_play', 10], 51 => ['pass_epa', 5], 55 => ['pass_epa', 10], 101 => ['rush_epa', 5]] as $start => [$metric, $size]) {
            foreach ([['top', 'top'], ['top', 'bottom'], ['bottom', 'top'], ['bottom', 'bottom']] as $offset => [$offense, $defense]) {
                $rules[$start + $offset] = compact('metric', 'size', 'offense', 'defense');
            }
        }
        $rules[9] = ['metric' => 'epa', 'size' => null, 'offense' => 'above_average', 'defense' => 'below_average'];
        $rules[10] = ['metric' => 'epa', 'size' => null, 'offense' => 'below_average', 'defense' => 'above_average'];
        $rules[34] = ['metric' => 'epa_stddev', 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        $rules[35] = ['metric' => 'epa_stddev', 'size' => 10, 'offense' => 'bottom', 'defense' => 'bottom'];
        $rules[38] = ['metric' => 'points_per_game', 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        $rules[39] = ['metric' => 'points_per_game', 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        foreach ([26 => 'early_epa', 28 => 'late_epa', 32 => 'explosive_rate', 59 => 'pass_success_rate',
            61 => 'pass_explosive_rate', 105 => 'rush_success_rate', 127 => 'short_yardage_success_rate'] as $id => $metric) {
            $rules[$id] = ['metric' => $metric, 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
            $rules[$id + 1] = ['metric' => $metric, 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        }
        foreach ([77 => 'first_down_pass_epa', 78 => 'third_down_pass_epa', 80 => 'red_zone_pass_epa',
            110 => 'rush_explosive_rate', 132 => 'first_down_rush_epa'] as $id => $metric) {
            $rules[$id] = ['metric' => $metric, 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        }
        $rules[79] = ['metric' => 'third_down_pass_epa', 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        $rules[63] = ['metric' => 'pass_yards_per_attempt', 'size' => 10, 'offense' => 'top', 'defense' => 'bottom'];
        $rules[64] = ['metric' => 'pass_yards_per_attempt', 'size' => 10, 'offense' => 'bottom', 'defense' => 'top'];
        foreach ([23 => ['first_down_rate', 'top', 'top'], 24 => ['first_down_rate', 'top', 'bottom'], 25 => ['first_down_rate', 'bottom', 'top'],
            30 => ['neutral_epa', 'top', 'bottom'], 31 => ['neutral_epa', 'bottom', 'top'],
            36 => ['drive_success_rate', 'top', 'bottom'], 37 => ['drive_success_rate', 'bottom', 'top'],
            67 => ['deep_pass_epa', 'top', 'bottom'], 68 => ['deep_pass_epa', 'top', 'top'],
            69 => ['intermediate_pass_epa', 'top', 'bottom'], 70 => ['short_pass_epa', 'top', 'bottom'],
            75 => ['shotgun_pass_epa', 'top', 'bottom'], 76 => ['under_center_pass_success', 'top', 'bottom'],
            81 => ['pass_td_rate', 'top', 'bottom'], 82 => ['interception_rate', 'top', 'bottom'],
            83 => ['interception_rate', 'bottom', 'top'], 129 => ['goal_line_rush_epa', 'top', 'bottom'],
            151 => ['sack_rate', 'bottom', 'top']] as $id => [$metric, $offense, $defense]) {
            $rules[$id] = compact('metric', 'offense', 'defense') + ['size' => 10];
        }
        foreach ([65 => ['cpoe', 'completion_rate', 'top', 'bottom'], 66 => ['cpoe', 'completion_rate', 'bottom', 'top'],
            90 => ['pass_rate', 'pass_epa', 'top', 'bottom'], 91 => ['pass_rate', 'pass_epa', 'top', 'top'],
            92 => ['pass_oe', 'pass_epa', 'bottom', 'bottom'], 93 => ['pass_oe', 'pass_epa', 'top', 'bottom'], 94 => ['pass_oe', 'pass_epa', 'top', 'top'],
            125 => ['rush_success_rate', 'rush_stuff_rate', 'top', 'bottom'], 126 => ['rush_success_rate', 'rush_stuff_rate', 'bottom', 'top'],
            130 => ['run_rate', 'rush_epa', 'top', 'bottom'], 131 => ['run_rate', 'rush_epa', 'top', 'top'],
            133 => ['early_run_rate', 'rush_epa', 'top', 'bottom']] as $id => [$metric, $defenseMetric, $offense, $defense]) {
            $rules[$id] = compact('metric', 'offense', 'defense') + ['defense_metric' => $defenseMetric, 'size' => 10];
        }
        foreach ([40 => ['epa_trend_3', 'improving', 'declining'], 41 => ['epa_trend_3', 'declining', 'improving'],
            95 => ['pass_epa_trend_3', 'improving', 'declining'], 96 => ['pass_epa_trend_3', 'declining', 'improving'],
            137 => ['rush_epa_trend_3', 'improving', 'declining'], 138 => ['rush_epa_trend_3', 'declining', 'improving'],
            44 => ['opponent_adjusted_epa', 'top', 'bottom'], 45 => ['opponent_adjusted_epa', 'bottom', 'top']] as $id => [$metric, $offense, $defense]) {
            $rules[$id] = compact('metric', 'offense', 'defense') + ['size' => 10];
        }
        $rules[42] = ['metric' => 'epa_trend_5', 'defense_metric' => 'epa', 'offense' => 'improving', 'defense' => 'any', 'size' => null];
        $rules[43] = ['metric' => 'epa', 'defense_metric' => 'epa_trend_5', 'offense' => 'any', 'defense' => 'improving', 'size' => null];
        foreach ([71 => ['play_action_epa', 'top', 'bottom'], 72 => ['play_action_epa', 'bottom', 'top'],
            73 => ['screen_pass_epa', 'top', 'bottom'], 74 => ['rpo_epa', 'top', 'bottom']] as $id => [$metric, $offense, $defense]) {
            $rules[$id] = compact('metric', 'offense', 'defense') + ['size' => 10];
        }
        foreach ([122 => ['rush_epa', 'defensive_box', 'top', 'top'], 123 => ['rush_epa', 'defensive_box', 'top', 'bottom'],
            124 => ['rush_epa', 'defensive_box', 'bottom', 'top'], 277 => ['motion_rate', 'motion_epa', 'top', 'bottom']] as $id => [$metric, $defenseMetric, $offense, $defense]) {
            $rules[$id] = compact('metric', 'offense', 'defense') + ['defense_metric' => $defenseMetric, 'size' => 10];
        }
        $rules[84] = ['metric' => 'air_yards_per_attempt', 'defense_metric' => 'deep_pass_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[97] = ['metric' => 'road_pass_epa', 'defense_metric' => 'home_pass_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10, 'venue' => 'road'];
        $rules[98] = ['metric' => 'home_pass_epa', 'defense_metric' => 'road_pass_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10, 'venue' => 'home'];
        foreach ([['top', 'top'], ['top', 'bottom'], ['bottom', 'top'], ['bottom', 'bottom']] as $offset => [$offense, $defense]) {
            $rules[191 + $offset] = ['metric' => 'qb_pass_epa', 'defense_metric' => 'pass_epa', 'size' => 10, 'offense' => $offense, 'defense' => $defense];
        }
        foreach ([167 => ['ol_changed', 1], 168 => ['ol_changed', 2], 169 => ['ol_same_four', 4]] as $id => [$personnel, $threshold]) {
            $rules[$id] = ['metric' => $personnel, 'personnel' => true, 'personnel_only' => true, 'offense_threshold' => $threshold, 'offense' => 'any', 'defense' => 'any', 'size' => null];
        }
        $rules[261] = ['metric' => 'wr1_out', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'pass_epa', 'offense' => 'any', 'defense' => 'top', 'size' => 10];
        $rules[262] = ['metric' => 'wr1_out', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'pass_epa', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        $rules[265] = ['metric' => 'rb1_out', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'rush_epa', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        $rules[166] = ['metric' => 'backup_center', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'blitz_rate', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        $rules[195] = ['metric' => 'qb_blitz_epa', 'defense_metric' => 'blitz_rate', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[196] = ['metric' => 'qb_blitz_epa', 'defense_metric' => 'blitz_rate', 'offense' => 'bottom', 'defense' => 'bottom', 'size' => 10];
        $rules[220] = ['metric' => 'qb_deep_epa', 'defense_metric' => 'deep_pass_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[225] = ['metric' => 'qb_play_action_epa', 'defense_metric' => 'play_action_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[226] = ['metric' => 'qb_rpo_epa', 'defense_metric' => 'rpo_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[237] = ['metric' => 'qb_pass_epa_trend_3', 'defense_metric' => 'pass_epa_trend_3', 'offense' => 'improving', 'defense' => 'declining', 'size' => null];
        $rules[238] = ['metric' => 'qb_pass_epa_trend_3', 'defense_metric' => 'pass_epa_trend_3', 'offense' => 'declining', 'defense' => 'improving', 'size' => null];
        $rules[230] = ['metric' => 'rookie_qb', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'blitz_rate', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        $rules[232] = ['metric' => 'rookie_qb', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'epa', 'offense' => 'any', 'defense' => 'top', 'size' => 10, 'venue' => 'road'];
        $rules[233] = ['metric' => 'backup_qb', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'epa', 'offense' => 'any', 'defense' => 'top', 'size' => 10];
        $rules[234] = ['metric' => 'backup_qb', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'epa', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        foreach ([49 => ['epa', .10], 50 => ['epa', .20], 99 => ['pass_epa', .10], 100 => ['pass_epa', -.10],
            139 => ['rush_epa', .10], 140 => ['rush_epa', -.20]] as $id => [$metric, $threshold]) {
            $rules[$id] = ['metric' => $metric, 'profile_threshold' => $threshold, 'offense' => 'any', 'defense' => 'any', 'size' => null];
        }
        $rules[46] = ['metric' => 'epa', 'zero_baseline' => true, 'offense' => 'positive', 'defense' => 'positive', 'size' => null];
        $rules[47] = ['metric' => 'epa', 'zero_baseline' => true, 'offense' => 'negative', 'defense' => 'negative', 'size' => null];
        foreach ([119 => ['qb_designed_run_rate', 'qb_run_epa'], 120 => ['qb_scramble_rate', 'scramble_epa'],
            134 => ['rb_run_explosive_rate', 'rb_run_explosive_rate'], 219 => ['qb_scramble_rate', 'qb_run_explosive_rate'],
            245 => ['te_target_epa', 'te_target_epa'], 254 => ['wr_deep_target_rate', 'pass_explosive_rate']] as $id => [$metric, $defenseMetric]) {
            $rules[$id] = ['metric' => $metric, 'defense_metric' => $defenseMetric, 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        }
        $rules[264] = ['metric' => 'te1_out', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'te_target_epa', 'offense' => 'any', 'defense' => 'bottom', 'size' => 10];
        $rules[266] = ['metric' => 'multiple_wr_out', 'personnel' => true, 'offense_threshold' => 2, 'defense_metric' => 'pass_epa', 'offense' => 'any', 'defense' => 'top', 'size' => 10];
        foreach ([256 => ['wr_target_concentration', 'cb1_out', 1], 267 => ['wr_target_epa', 'secondary_out', 2],
            270 => ['deep_pass_epa', 'safety_out', 1], 272 => ['te_target_share', 'lb_out', 1], 273 => ['rb_target_share', 'lb_out', 1]] as $id => [$metric, $defenseMetric, $threshold]) {
            $rules[$id] = ['metric' => $metric, 'defense_metric' => $defenseMetric, 'defense_personnel' => true,
                'defense_threshold' => $threshold, 'offense' => 'top', 'defense' => 'any', 'size' => 10];
        }
        $rules[236] = ['metric' => 'qb_recent_change', 'personnel' => true, 'offense_threshold' => 1, 'defense_metric' => 'epa', 'offense' => 'any', 'defense' => 'top', 'size' => 10];
        $rules[224] = ['metric' => 'qb_checkdown_rate', 'defense_metric' => 'short_pass_epa', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[182] = ['metric' => 'false_start_rate', 'offense_only' => true, 'venue' => 'road', 'offense' => 'top', 'defense' => 'any', 'size' => 10];
        $rules[227] = ['metric' => 'qb_turnover_rate', 'defense_metric' => 'takeaway_play_rate', 'offense' => 'top', 'defense' => 'top', 'size' => 10];
        $rules[228] = ['metric' => 'qb_turnover_rate', 'defense_metric' => 'takeaway_play_rate', 'offense' => 'bottom', 'defense' => 'top', 'size' => 10];
        $rules[248] = ['metric' => 'wr_target_weight', 'defense_metric' => 'cb_weight', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[249] = ['metric' => 'wr_target_height', 'defense_metric' => 'secondary_height', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        foreach ([201 => 'man', 203 => 'zone', 205 => 'cover_1', 207 => 'cover_2', 209 => 'cover_3', 211 => 'cover_4'] as $id => $coverage) {
            foreach (['top', 'bottom'] as $offset => $band) {
                $rules[$id + $offset] = ['metric' => 'qb_'.$coverage.'_epa', 'defense_metric' => $coverage.'_rate',
                    'offense' => $band, 'defense' => 'bottom', 'size' => 10, 'coverage' => true];
            }
        }
        foreach ([149 => ['pressure_rate', 'pressure_rate', 'top'], 150 => ['pressure_rate', 'pressure_rate', 'bottom'],
            152 => ['sack_rate', 'pressure_rate', 'top'], 153 => ['pressure_to_sack_rate', 'pressure_rate', 'bottom'],
            177 => ['pressure_rate', 'blitz_rate', 'bottom'], 178 => ['pressure_rate', 'blitz_rate', 'top'],
            199 => ['qb_pressure_to_sack_rate', 'pressure_rate', 'top'], 200 => ['qb_pressure_to_sack_rate', 'pressure_rate', 'bottom']] as $id => [$metric, $defenseMetric, $band]) {
            $rules[$id] = ['metric' => $metric, 'defense_metric' => $defenseMetric, 'offense' => $band,
                'defense' => $defenseMetric === 'blitz_rate' ? 'bottom' : 'top', 'size' => 10];
        }
        foreach ([154 => 1, 155 => 2, 156 => 3] as $id => $count) {
            $rules[$id] = ['metric' => 'ol_out', 'personnel' => true, 'offense_threshold' => $count,
                'offense_maximum' => $count < 3 ? $count : 5, 'defense_metric' => 'pressure_rate',
                'offense' => 'any', 'defense' => 'top', 'size' => 10];
        }
        $rules[179] = ['metric' => 'pressure_rate', 'defense_metric' => 'four_man_rush_rate', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        foreach ([85 => ['yac_per_catch', 'top', 'bottom'], 86 => ['yac_per_catch', 'bottom', 'top'],
            255 => ['wr_yac_per_catch', 'top', 'bottom']] as $id => [$metric, $offense, $defense]) {
            $rules[$id] = ['metric' => $metric, 'defense_metric' => 'missed_tackle_rate', 'offense' => $offense, 'defense' => $defense, 'size' => 10];
        }
        $rules[222] = ['metric' => 'qb_release_time', 'defense_metric' => 'pressure_rate', 'offense' => 'top', 'defense' => 'top', 'size' => 10];
        $rules[223] = ['metric' => 'qb_release_time', 'defense_metric' => 'pressure_rate', 'offense' => 'bottom', 'defense' => 'top', 'size' => 10];
        $rules[107] = ['metric' => 'rush_ybc', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[108] = ['metric' => 'rush_ybc', 'offense' => 'bottom', 'defense' => 'top', 'size' => 10];
        $rules[109] = ['metric' => 'rush_yac', 'defense_metric' => 'missed_tackle_rate', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10];
        $rules[218] = ['metric' => 'qb_scramble_rate', 'defense_metric' => 'man_rate', 'offense' => 'top', 'defense' => 'bottom', 'size' => 10, 'coverage' => true];
        foreach ([176 => ['charted_pressure_rate', 'four_rusher_pressure_rate', 'bottom', 'top'],
            197 => ['qb_pressure_epa', 'charted_pressure_rate', 'top', 'top'],
            198 => ['qb_pressure_epa', 'charted_pressure_rate', 'bottom', 'top'],
            253 => ['wr_zone_target_epa', 'zone_pass_epa', 'top', 'bottom'],
            274 => ['personnel_12_rate', 'personnel_12_pass_epa', 'top', 'bottom'],
            275 => ['personnel_11_rate', 'five_db_epa', 'top', 'bottom'],
            276 => ['personnel_21_rate', 'four_db_epa', 'top', 'bottom']] as $id => [$metric, $defenseMetric, $offense, $defense]) {
            $rules[$id] = ['metric' => $metric, 'defense_metric' => $defenseMetric, 'offense' => $offense,
                'defense' => $defense, 'size' => 10, 'participation' => true];
        }
        ksort($rules);

        return $rules;
    }

    public function entries(): array
    {
        $rules = $this->rules();
        $entries = [];
        foreach (explode("\n", self::LABELS) as $line) {
            [$id, $label] = explode('. ', $line, 2);
            $id = (int) $id;
            if (in_array($id, [167, 168, 169], true)) {
                $label = 'Projected: '.$label;
            }
            $category = match (true) {
                $id <= 50 => 'overall', $id <= 100 => 'passing', $id <= 140 => 'rushing',
                $id <= 190 => 'offensive_line', $id <= 240 => 'quarterback_scheme',
                $id <= 280 => 'receivers_coverage', $id <= 320 => 'situational_records',
                default => 'travel_home_road',
            };
            $support = isset($rules[$id]) ? 'implemented' : ($id >= 281 ? 'situational_records' : 'unavailable');
            $reason = match ($support) {
                'implemented' => null,
                'situational_records' => $id === 353 ? 'Incomplete source definition; home-rest threshold is unspecified.' : 'Evaluated separately in situational records where the required inputs and definitions exist.',
                default => in_array($category, ['offensive_line', 'quarterback_scheme', 'receivers_coverage'], true)
                    ? 'Requires validated charting, personnel or injury inputs and an explicit threshold; no proxy is substituted.'
                    : 'Metric derivation or threshold is not yet validated; no signal is inferred from missing inputs.',
            };
            $entries[] = compact('id', 'label', 'category', 'support', 'reason');
        }

        return array_map(function (array $entry) use ($rules): array {
            $metric = $rules[$entry['id']]['metric'] ?? null;
            $positionInputs = in_array($entry['id'], [119, 120, 134, 219, 236, 245, 254, 256, 264, 266, 267, 270, 272, 273], true)
                ? ['Season/team-specific GSIS roster position mappings', 'Identified rushing and receiving play-by-play with complete league coverage', 'Game-linked player identity and timestamped injury evidence where named'] : null;
            if (in_array($entry['id'], [149, 150, 152, 153, 177, 178, 199, 200], true)) {
                $positionInputs = ['PFR weekly pressure and sack counts via nflverse', 'Unique season/team PFR-to-GSIS roster identities and verified game/opponent pairs', 'Complete play-by-play dropbacks and matching sacks; all 32 teams, plus 24 qualified QBs for QB rules'];
            }
            if ($rules[$entry['id']]['participation'] ?? false) {
                $positionInputs = ['FTN postseason pressure/personnel/coverage charting joined by verified play IDs', 'Explicit selected season and complete 32-team samples', 'Game-selected QB or receiver roster identities where required'];
            }
            if ($rules[$entry['id']]['coverage'] ?? false) {
                $positionInputs = ['Game-selected QB identity and availability', 'FTN Data via nflverse participation, released after postseason (CC-BY-SA 4.0)', 'Explicit evidence season, at least 24 qualified QBs and all 32 defensive usage profiles'];
            }
            if (in_array($entry['id'], [154, 155, 156], true)) {
                $positionInputs = ['Fresh game-linked depth chart with five unique LT/LG/C/RG/RT identities', 'Complete timestamped injury-status evidence for all five projected starters', 'Verified PFR pressure counts and complete 32-team play-by-play dropback samples'];
            }
            if ($entry['id'] === 179) {
                $positionInputs = ['Verified PFR pressure counts and complete league dropbacks', 'FTN exact pass-rusher counts joined to game/play identities', 'At least two complete prior games for all 32 teams'];
            }
            if (in_array($entry['id'], [107, 108, 109], true)) {
                $positionInputs = ['PFR weekly rushing contact yards and carries via nflverse', 'Verified game/team/opponent mapping and complete play-by-play carry counts', 'Complete current-season samples for all 32 teams; PFR team missed tackles for rule 109'];
            }
            if (in_array($entry['id'], [85, 86, 255], true)) {
                $positionInputs = ['Completed-pass yards after catch and complete league play-by-play', 'PFR missed and combined tackle counts for every prior team game', 'Unambiguous season/team WR roster positions for the WR-only rule'];
            }
            if (in_array($entry['id'], [222, 223], true)) {
                $positionInputs = ['Weekly NGS passing release times and attempt counts (season totals excluded)', 'Game-selected QB identity, availability and identified prior passing appearances', 'Verified complete-league PFR pressure counts and nflverse dropbacks'];
            }
            if ($entry['id'] === 224) {
                $positionInputs = ['Game-selected quarterback identity and availability', 'FTN read_thrown charting joined by verified game/play identity', 'At least 24 qualified QBs and short-pass defensive samples for all 32 teams'];
            }
            if (in_array($entry['id'], [248, 249], true)) {
                $positionInputs = ['Unambiguous season/team WR roster dimensions and identified pass targets', 'Fresh game-linked defensive depth charts with complete starter dimensions', 'Thirty-two qualified offensive and projected defensive size profiles'];
            }

            return [...$entry, 'definition' => $metric ? $this->definition($metric).(($rules[$entry['id']]['zero_baseline'] ?? false) ? ' Uses the provider EPA zero baseline, not league rank or league-average centering. Positive offensive EPA means expected points added; positive EPA allowed means the defense allowed expected points to be added. Negative values mean expected points lost or suppressed, respectively. Both sides must be strictly on the named side of zero; zero does not qualify. This is play-level expected-points performance, not actual scoreboard points minus predicted game scores.' : '').(isset($rules[$entry['id']]['profile_threshold']) ? ' EPA profile differential = (offense EPA minus the league mean offensive EPA) + (opponent EPA allowed minus the league mean defensive EPA allowed). League means weight all 32 qualified teams equally. Positive favors the offensive profile; negative favors the defensive profile. The threshold is '.($rules[$entry['id']]['profile_threshold'] > 0 ? 'at least +' : 'at most ').number_format($rules[$entry['id']]['profile_threshold'], 2).' EPA/play, inclusive. These are catalog description thresholds, not backtested edges, predicted scoring margins or forecast inputs.' : '').(isset($rules[$entry['id']]['defense_metric']) ? ' Defense comparison: '.$this->definition($rules[$entry['id']]['defense_metric']) : '') : null,
                'required_inputs' => $positionInputs ?? ($metric === 'rookie_qb' ? ['Game-selected quarterback identity and availability', 'Unambiguous target-season team roster experience', 'Complete league defensive play-by-play'] : (($rules[$entry['id']]['personnel'] ?? false) ? ['Game-linked target depth chart', 'Timestamped charts observed before historical kickoffs', 'Explicit player-ID injury evidence where required'] : ($metric ? ($metric === 'points_per_game' ? ['Final team scores', 'Complete league schedule'] : ['Mapped nflverse play-by-play', 'Eligible play values and situational fields', 'Complete league schedule']) : $this->requirements($entry['id'], $entry['category'])))),
                'prediction_effect' => 'none'];
        }, $entries);
    }

    public function definition(string $metric): string
    {
        if (in_array($metric, ['epa_stddev', 'qb_pass_epa_trend_3'], true)) {
            return NflMatchupMetricDefinitions::definition($metric);
        }

        return (NflMatchupMetricDefinitions::definition($metric) ?? match ($metric) {
            'epa', 'pass_epa', 'rush_epa' => 'Play-weighted EPA on eligible pass/run plays; sacks count as passes. Higher offense and lower EPA allowed rank better.',
            'success_rate', 'pass_success_rate', 'rush_success_rate' => 'Share of eligible plays with EPA > 0, within the named play type. Defense is opponent success allowed; lower is better.',
            'explosive_rate', 'pass_explosive_rate', 'rush_explosive_rate' => 'Share of eligible plays gaining at least 20 passing yards or 10 rushing yards. Defense is explosive plays allowed; lower is better.',
            'early_epa' => 'Play-weighted EPA on first and second downs.',
            'late_epa' => 'Play-weighted EPA on third and fourth downs.',
            'first_down_pass_epa' => 'Passing EPA on first down, including sacks.',
            'third_down_pass_epa' => 'Passing EPA on third down, including sacks.',
            'red_zone_pass_epa' => 'Passing EPA when the ball is 0–20 yards from the opponent end zone, including sacks.',
            'first_down_rush_epa' => 'Rushing EPA on first down; provider-classified runs, excluding sacks.',
            'short_yardage_success_rate' => 'Share of eligible pass/run plays with 1–2 yards to go that gain at least the required yards. Not a charted blocking grade.',
            'yards_per_play' => 'Play-weighted yards gained on eligible pass/run plays, including sacks.',
            'pass_yards_per_attempt' => 'Passing yards gained per provider-classified pass with a known false sack flag. Includes incomplete passes as zero yards; excludes sacks, runs, no-play rows and unknown sack classifications. Requires at least 15 attempts per game and 90% yardage coverage.',
            'points_per_drive' => 'Possession-team scoreboard points gained per completed drive containing a pass or run. Includes conversion points; excludes return-only and kneel-only drives and opponent scores. Requires complete drive identities, results and score coverage, at least five drives per game and two complete games for all 32 teams.',
            'points_per_game' => 'Team points scored/allowed per final game, including defensive and special-teams scoring.',
        }).' High/elite/strong means top 10 and low/weak/poor means bottom 10 where the supplied label has no numeric band; explicit top-5/top-10 bands take precedence. Thresholds describe this catalog, not validated betting edges.';
    }

    private function requirements(int $id, string $category): array
    {
        if ($id === 353) {
            return ['Complete definition of home-rest and its threshold'];
        }

        return match ($category) {
            'overall' => ['Validated metric derivation (drive, script, opponent adjustment or trend as named)', 'Explicit threshold and historical league sample'],
            'passing' => ['Charted passing split or receiver/tracking input named by this rule', 'Explicit threshold and verified historical coverage'],
            'rushing' => ['Charted run concept, contact, box or personnel input named by this rule', 'Explicit threshold and verified historical coverage'],
            'offensive_line' => ['Charted pass/run blocking and defensive-front data', 'Timestamped starter, injury and lineup history where named'],
            'quarterback_scheme' => ['QB-level charted coverage/pressure/scheme splits', 'Timestamped QB identity and availability', 'Explicit comparison threshold'],
            'receivers_coverage' => ['Verified receiver/coverage assignments or tracking data', 'Personnel and injury history where named', 'Explicit comparison threshold'],
            'situational_records' => ['Complete schedule and prior-game context for the named situation', 'Immutable pregame market snapshots for ATS/O-U'],
            default => ['Verified venue, travel, timezone, roof or historical weather context as named', 'Immutable pregame market snapshots for favorite/underdog/ATS rules'],
        };
    }

    private const LABELS = <<<'LABELS'
1. Top-5 offensive EPA vs top-5 defensive EPA
2. Top-5 offensive EPA vs bottom-5 defensive EPA
3. Bottom-5 offensive EPA vs top-5 defensive EPA
4. Bottom-5 offensive EPA vs bottom-5 defensive EPA
5. Top-10 offensive EPA vs top-10 defensive EPA
6. Top-10 offensive EPA vs bottom-10 defensive EPA
7. Bottom-10 offensive EPA vs top-10 defensive EPA
8. Bottom-10 offensive EPA vs bottom-10 defensive EPA
9. Above-average offensive EPA vs below-average defensive EPA
10. Below-average offensive EPA vs above-average defensive EPA
11. Top-10 offensive success rate vs top-10 defensive success rate
12. Top-10 offensive success rate vs bottom-10 defense
13. Bottom-10 offensive success rate vs top-10 defense
14. Bottom-10 offensive success rate vs bottom-10 defense
15. Top-10 points/drive offense vs top-10 points/drive defense
16. Top-10 points/drive offense vs bottom-10 defense
17. Bottom-10 points/drive offense vs top-10 defense
18. Bottom-10 points/drive offense vs bottom-10 defense
19. Top-10 yards/play offense vs top-10 yards/play defense
20. Top-10 yards/play offense vs bottom-10 defense
21. Bottom-10 yards/play offense vs top-10 defense
22. Bottom-10 yards/play offense vs bottom-10 defense
23. Top-10 first-down rate vs top-10 first-down defense
24. Top-10 first-down rate vs bottom-10 defense
25. Bottom-10 first-down rate vs top-10 defense
26. Top-10 early-down EPA vs bottom-10 early-down defense
27. Bottom-10 early-down EPA vs top-10 early-down defense
28. Top-10 late-down EPA vs bottom-10 late-down defense
29. Bottom-10 late-down EPA vs top-10 late-down defense
30. Top-10 neutral-script EPA vs bottom-10 defense
31. Bottom-10 neutral EPA vs top-10 defense
32. Top-10 explosive-play offense vs bottom-10 explosive defense
33. Bottom-10 explosive offense vs top-10 explosive defense
34. High consistency offense vs high-variance defense
35. High-variance offense vs high-variance defense
36. Top-10 drive success vs bottom-10 drive defense
37. Bottom-10 drive success vs top-10 drive defense
38. Top-10 scoring offense vs bottom-10 scoring defense
39. Bottom-10 scoring offense vs top-10 scoring defense
40. Offense improving 3 straight games vs declining defense
41. Offense declining 3 straight vs improving defense
42. Offense improving 5-game EPA vs season defense
43. Season offense vs defense improving last 5
44. Top offense by opponent-adjusted EPA vs weak defense
45. Weak opponent-adjusted offense vs elite defense
46. Positive offensive EPA vs positive EPA allowed
47. Negative offensive EPA vs negative EPA allowed
48. Top offensive DVOA-style efficiency vs bottom defensive efficiency
49. Overall EPA profile differential ≥ +0.10 EPA/play
50. Overall EPA profile differential ≥ +0.20 EPA/play
51. Top-5 passing EPA vs top-5 pass defense
52. Top-5 passing EPA vs bottom-5 pass defense
53. Bottom-5 passing EPA vs top-5 pass defense
54. Bottom-5 passing EPA vs bottom-5 pass defense
55. Top-10 passing EPA vs top-10 pass defense
56. Top-10 passing EPA vs bottom-10 pass defense
57. Bottom-10 passing EPA vs top-10 pass defense
58. Bottom-10 passing EPA vs bottom-10 pass defense
59. High pass success rate vs low defensive pass success
60. Low pass success vs elite defensive pass success
61. High explosive-pass rate vs high explosive-pass allowed
62. Low explosive passing vs elite explosive-pass prevention
63. High yards/attempt vs high YPA allowed
64. Low YPA vs low YPA allowed
65. High CPOE offense vs low completion defense
66. Low CPOE offense vs elite completion defense
67. High deep-pass EPA vs weak deep defense
68. High deep-pass EPA vs elite deep defense
69. High intermediate EPA vs weak intermediate defense
70. High short-pass EPA vs weak underneath defense
71. High play-action EPA vs defense weak against play action
72. Poor play-action offense vs defense strong against it
73. High screen EPA vs defense weak against screens
74. High RPO EPA vs defense weak against RPO
75. High shotgun passing EPA vs weak shotgun defense
76. Under-center passing success vs weak under-center defense
77. High first-down passing EPA vs weak first-down pass defense
78. High third-down passing EPA vs weak third-down pass defense
79. Poor third-down passing vs elite third-down pass defense
80. High red-zone passing EPA vs poor red-zone pass defense
81. High passing TD rate vs high TD rate allowed
82. Low INT offense vs low takeaway defense
83. High INT offense vs high takeaway defense
84. High air yards/attempt vs weak deep secondary
85. High YAC per catch vs high team missed-tackle rate
86. Low YAC per catch vs low team missed-tackle rate
87. High receiver separation vs man-heavy defense
88. Low receiver separation vs man-heavy defense
89. High contested-catch offense vs physical secondary
90. Pass-heavy offense vs weak pass defense
91. Pass-heavy offense vs elite pass defense
92. Low-PROE offense vs weak pass defense
93. High-PROE offense vs weak pass defense
94. High-PROE offense vs elite pass defense
95. Passing offense trending up vs pass defense trending down
96. Passing offense trending down vs defense trending up
97. Top road passing offense vs weak home pass defense
98. Top home passing offense vs weak road pass defense
99. Passing EPA profile differential ≥ +0.10 EPA/play
100. Passing EPA profile differential ≤ -0.10 EPA/play
101. Top-5 rush EPA vs top-5 run defense
102. Top-5 rush EPA vs bottom-5 run defense
103. Bottom-5 rush EPA vs top-5 run defense
104. Bottom-5 rush EPA vs bottom-5 run defense
105. Top-10 rush success vs bottom-10 run defense
106. Bottom-10 rush success vs top-10 run defense
107. High rushing yards before contact vs high yards before contact allowed
108. Low rushing yards before contact vs low yards before contact allowed
109. High rushing yards after contact vs high team missed-tackle rate
110. Explosive rushing offense vs explosive runs allowed
111. Outside-zone offense vs weak outside-zone defense
112. Outside-zone offense vs elite outside-zone defense
113. Inside-zone offense vs weak inside-zone defense
114. Inside-zone offense vs elite inside-zone defense
115. Gap/power offense vs weak gap defense
116. Gap/power offense vs elite gap defense
117. Counter-heavy offense vs weak counter defense
118. Duo-heavy offense vs weak duo defense
119. High non-scramble QB run share vs weak QB-run defense
120. High QB scramble rate vs high scrambling EPA allowed
121. RB-heavy offense vs weak linebacker unit
122. Strong rushing offense vs light defensive boxes
123. Strong rushing offense vs heavy boxes
124. Weak rushing offense vs light boxes
125. High rush success vs low stuff rate defense
126. Low rush success vs high stuff rate defense
127. Top short-yardage offense vs weak short-yardage defense
128. Poor short-yardage offense vs elite short-yardage defense
129. Top goal-line rushing offense vs weak goal-line defense
130. Run-heavy offense vs bottom-10 run defense
131. Run-heavy offense vs top-10 run defense
132. High first-down rush EPA vs poor first-down run defense
133. High early-down rush rate vs weak run defense
134. Explosive RB rushing unit vs defense allowing explosive RB runs
135. Strong OL rushing metrics vs weak DL
136. Weak OL rushing metrics vs strong DL
137. Rush offense trending up vs run defense trending down
138. Rush offense trending down vs run defense trending up
139. Rushing EPA profile differential ≥ +0.10 EPA/play
140. Rushing EPA profile differential ≤ -0.20 EPA/play
141. Top-5 pass-block OL vs top-5 pass rush
142. Top-5 OL vs bottom-5 pass rush
143. Bottom-5 OL vs top-5 pass rush
144. Bottom-5 OL vs bottom-5 pass rush
145. Top-10 pass-block win rate vs top-10 pass-rush win rate
146. Top-10 PBWR vs bottom-10 PRWR
147. Bottom-10 PBWR vs top-10 PRWR
148. Bottom-10 PBWR vs bottom-10 PRWR
149. Low pressure allowed vs high pressure defense
150. High pressure allowed vs high pressure defense
151. High sack allowed rate vs high sack defense
152. Low sack rate vs high-pressure defense
153. High team sacks per pressure vs high-pressure defense
154. Exactly one projected OL starter unavailable vs top-10 pressure defense
155. Exactly two projected OL starters unavailable vs top-10 pressure defense
156. Three-plus projected OL starters unavailable vs top-10 pressure defense
157. LT out vs elite edge
158. RT out vs elite edge
159. Both tackles compromised vs strong edge duo
160. Center out vs elite interior DL
161. Guard out vs elite interior pressure
162. Multiple interior OL injuries vs interior-heavy rush
163. Rookie LT vs elite edge
164. Rookie RT vs elite edge
165. Backup tackle vs top-10 edge
166. Backup center vs high blitz rate
167. OL lineup changed from previous week
168. 2+ OL lineup changes
169. Same five OL starters 4+ consecutive games
170. High OL continuity vs unstable defensive front
171. Low OL continuity vs stable defensive front
172. Top run-block win rate vs poor run defense
173. Bottom run-block win rate vs elite run defense
174. Strong zone-blocking OL vs weak zone front
175. Strong gap-blocking OL vs weak gap front
176. High pressure allowed vs high pressure from exactly four rushers
177. High pressure allowed vs blitz-heavy defense
178. Low pressure allowed vs blitz-heavy defense
179. Low pressure allowed vs frequent four-man rush
180. OL penalty-heavy vs disciplined DL
181. High holding rate vs elite edge
182. High false-start rate in road environment
183. OL disadvantage + road game
184. OL disadvantage + backup QB
185. OL disadvantage + immobile QB
186. OL advantage + mobile QB
187. OL advantage + elite passing QB
188. OL advantage + weak secondary
189. Large PBWR–PRWR differential
190. Extreme PBWR–PRWR differential
191. QB top-10 EPA vs top-10 defense
192. QB top-10 EPA vs bottom-10 defense
193. QB bottom-10 EPA vs top-10 defense
194. QB bottom-10 EPA vs bottom-10 defense
195. QB elite vs blitz vs blitz-heavy defense
196. QB poor vs blitz vs blitz-heavy defense
197. QB elite vs pressure vs high-pressure defense
198. QB poor vs pressure vs high-pressure defense
199. QB low pressure-to-sack rate vs high-pressure defense
200. QB high pressure-to-sack rate vs high-pressure defense
201. QB strong vs man vs man-heavy defense
202. QB weak vs man vs man-heavy defense
203. QB strong vs zone vs zone-heavy defense
204. QB weak vs zone vs zone-heavy defense
205. QB strong vs Cover 1 vs Cover-1-heavy defense
206. QB weak vs Cover 1 vs Cover-1-heavy defense
207. QB strong vs Cover 2 vs Cover-2-heavy defense
208. QB weak vs Cover 2 vs Cover-2-heavy defense
209. QB strong vs Cover 3 vs Cover-3-heavy defense
210. QB weak vs Cover 3 vs Cover-3-heavy defense
211. QB strong vs Cover 4 vs Cover-4-heavy defense
212. QB weak vs Cover 4 vs Cover-4-heavy defense
213. QB strong vs two-high safety looks
214. QB weak vs two-high safety looks
215. QB strong vs single-high
216. QB weak vs single-high
217. Mobile QB vs weak contain defense
218. High selected-QB scramble rate vs man-heavy defense
219. High QB scramble rate vs defense allowing explosive QB runs
220. QB deep-ball strength vs weak deep defense
221. Poor deep passer vs defense forcing deep throws
222. Quick-release QB vs high-pressure defense
223. Slow-release QB vs high-pressure defense
224. High charted QB checkdown rate vs weak short-pass EPA defense
225. High play-action EPA QB vs play-action weakness
226. High RPO passing EPA QB vs RPO pass-defense weakness
227. High QB turnover rate vs high takeaway-play defense
228. Low QB turnover rate vs high takeaway-play defense
229. QB first start vs top defense
230. Rookie QB vs blitz-heavy defense
231. Rookie QB vs disguise-heavy defense
232. Rookie QB on road vs top defense
233. Selected QB listed as backup vs top-10 defense
234. Selected QB listed as backup vs bottom-10 defense
235. QB returning from injury vs high-pressure defense
236. Latest projected QB changed within 7 days vs top-10 defense
237. QB passing EPA improving 3 straight appearances vs declining pass defense
238. QB passing EPA declining 3 straight appearances vs improving pass defense
239. QB scheme-matchup advantage score
240. QB scheme-matchup disadvantage score
241. Elite WR1 vs weak CB1
242. Elite WR1 vs elite CB1
243. Elite WR2 vs weak CB2
244. Strong slot WR vs weak nickel CB
245. High TE-target EPA offense vs weak TE-target defense
246. Strong receiving RB vs poor LB coverage
247. Speed WR vs slow secondary
248. Heavier target-weighted WR unit vs lighter projected cornerbacks
249. Taller target-weighted WR unit vs shorter projected secondary
250. Elite route runner vs man-heavy defense
251. High-separation WR vs man defense
252. Low-separation receivers vs man defense
253. High WR-target EPA against zone vs weak zone pass defense
254. High WR deep-target rate vs explosive-pass weakness
255. High WR YAC per catch vs high team missed-tackle rate
256. High individual-WR target concentration vs lead CB unavailable
257. WR1 vs replacement CB
258. WR2 vs replacement CB
259. Slot WR vs replacement nickel
260. TE vs backup safety/LB
261. Lead WR out vs top-10 pass defense
262. Lead WR out vs bottom-10 pass defense
263. WR2 out vs elite secondary
264. TE1 out vs defense weak against TE
265. RB1 out vs weak run defense
266. Multiple starting WRs unavailable vs top-10 pass defense
267. High WR-target EPA vs multiple secondary starters unavailable
268. CB1 out vs high-target-share WR1
269. CB2 out vs deep WR2
270. High deep-passing EPA vs starting safety unavailable
271. Nickel CB out vs slot-heavy offense
272. High TE target share vs starting linebacker unavailable
273. High RB target share vs starting linebacker unavailable
274. High 12-personnel usage vs weak pass defense against 12 personnel
275. High 11-personnel usage vs weak five-DB personnel defense
276. High 21-personnel usage vs weak four-DB personnel defense
277. Motion-heavy offense vs poor motion defense
278. Bunch-heavy offense vs man-heavy defense
279. Receiver matchup advantage across 2+ positions
280. Defense has coverage advantage across 2+ positions
281. Team record after bye
282. Team ATS after bye
283. Team O/U after bye
284. Team record before bye
285. Team ATS before bye
286. Record when opponent is off bye
287. ATS when opponent is off bye
288. Record with 2+ rest-day advantage
289. ATS with 2+ rest-day advantage
290. Record with 4+ rest-day advantage
291. ATS with 4+ rest-day advantage
292. Record with rest disadvantage
293. ATS with rest disadvantage
294. Record on Thursday
295. ATS on Thursday
296. Record Sunday after Monday game
297. ATS Sunday after Monday game
298. Record after Thursday mini-bye
299. ATS after Thursday mini-bye
300. Record after overtime
301. ATS after overtime
302. Record after 70+ offensive snaps
303. Record after defense played 70+ snaps
304. Record after blowout win
305. ATS after blowout win
306. Record after blowout loss
307. ATS after blowout loss
308. Record after one-score win
309. ATS after one-score win
310. Record after one-score loss
311. ATS after one-score loss
312. Record after 2 straight wins
313. Record after 3+ straight wins
314. Record after 2 straight losses
315. Record after 3+ straight losses
316. ATS after 3+ wins
317. ATS after 3+ losses
318. Record following divisional game
319. Record before divisional game
320. Record between two divisional games
321. Team home record
322. Team home ATS
323. Team road record
324. Team road ATS
325. Record as home favorite
326. ATS as home favorite
327. Record as home underdog
328. ATS as home underdog
329. Record as road favorite
330. ATS as road favorite
331. Record as road underdog
332. ATS as road underdog
333. Record second consecutive road game
334. ATS second consecutive road game
335. Record third consecutive road game
336. Record 1,000+ miles from home venue
337. Record 2,000+ miles from home venue
338. One-hour home-to-game clock difference
339. Two-plus-hour home-to-game clock difference
340. Game clock ahead of home clock
341. Game clock ahead of home, early afternoon kickoff
342. Game clock behind home clock
343. Record following international game
344. Record in international game
345. Record at elevations of 1,500+ meters
346. Low-elevation home team at altitude
347. Roofed-home team outdoors
348. Outdoor-home team under a roof
349. Warm-weather team in cold game
350. Cold-weather team in hot game
351. Road team after home-heavy stretch
352. Home team after road-heavy stretch
353. Record with home-rest
LABELS;
}
