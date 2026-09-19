# CFB football signal catalog

Version: `cfb-football-signals-v1`. The catalog contains 200 distinct executable conditions, each evaluated in team/opponent orientation. Home and away evaluations are not separate catalog entries.

**Registration is not validation.** These are research hypotheses with predefined cohort boundaries, not 200 proven betting edges. Conditions have no hand-assigned prediction coefficient. The residual learner must support a condition using historical game evidence and chronological held-out validation before it can alter a forecast. Evidence distinguishes original frozen forecasts from retrospectively reconstructed historical inputs. Legacy release 1.6.0 averages families. Release 1.6.1 fits related conditions jointly using ridge regression with a fixed penalty of 100 and no search over penalties; additive contributions are capped at two spread points and three total points.

Release 1.6.1 also schedules `cfb:train-football-signals` daily. The job reconstructs prior-game trends from historical results and box scores, excludes the target game and all same-day/future games, and uses only prior-season FPI instead of target-season final ratings. Revised historical data is explicitly labeled retrospective, with historical availability unproven. Archived market/weather/personnel observations are not fabricated. The reconstruction uses the target baseline formula but prior-season FPI and unavailable context can differ from live inputs; this remains a material transfer limitation, not proof of live accuracy or profitability. Artifacts contain source game IDs, retrieval and availability times, versioned configuration, and expire after eight days without a successful refresh.

In release 1.6.1, eligible older snapshots are replayed through the target baseline with signal corrections disabled. This permits training across release versions without combining residuals from different baseline formulas or fetching present-day ratings. Replayed prediction IDs and training policy are frozen in the evidence; these are retrospective calculations on original pregame inputs, not claims that the new model ran at the time. Original snapshot/publication timestamps and outcome availability checks remain enforced.

The joint learner fits the first 70% of chronologically ordered games, discards training games on the validation boundary date, and tests the remaining games without refitting. It requires 100 training games, 50 validation games, and at least 20 training exposures per weighted condition. Acceptance requires positive whole-model MAE improvement greater than 1.96 standard errors; this replaces 200 standalone significance gates. The exact capped and rounded adjustment is evaluated. Per-rule validation fields refer to the joint market model, not standalone significance. Unknown conditions contribute no feature activation, and their missing status stays visible.

The spread outcome is actual team margin minus the frozen baseline team margin. The total outcome is actual total minus the frozen baseline total. The direction and magnitude are learned from historical residuals; the labels do not assert an advantage.

Release 1.6.0 requires three observed eligible games per historical metric. Release 1.6.1 uses `early_season_prior_v1` for current-season level conditions: one or two observed games are blended with three game-equivalents of the actual prior-season mean (which must have at least three observations). With zero current observations, the prior alone is explicitly labeled. At three current observations the observed current mean is used. Recent-change and venue conditions retain their original sample requirements. Frozen scoring aggregates from older snapshots can supply the same season-level measures; unrelated or stale seasons cannot. Per-rule `input_support` records the sample source and weights. This policy is an explicit prior, not evidence that an unobserved current-season performance occurred. Last-three versus prior-season comparisons measure changes in different football mechanisms, not independent copies of home/away fields. Missing or unverified personnel, absent market quotes, and insufficient quarter samples remain unknown. Outdoor weather is suppressed for known indoor venues.

Source adapter: `CfbFootballSignalCatalog::features($frozenInputs, $side)`. Historical paths come from `historical_signals.<side>.windows.<window>.metrics.<metric>`. Current/prior metrics, versioned Elo, verified personnel and large-spread quarter evidence come from the same frozen canonical snapshot. Context comes from `signal_context` plus frozen event and rest evidence. No live lookups occur during evaluation.

These rules do not measure travel distance or time-zone displacement: those measurements are not currently frozen in this input contract. Stored play-attempt volume is a pace proxy, not actual tempo. Fourth-quarter performance does not establish garbage time or backdoor-cover causation.

## Matchup (30)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `scoring_pressure` | Scoring offense faces permissive scoring defense | spread | `team.history.current_season.points_per_game >= 35`; `opponent.history.current_season.points_allowed_per_game >= 28` |
| `defensive_control` | Low allowance defense faces low scoring offense | spread | `team.history.current_season.points_allowed_per_game <= 20`; `opponent.history.current_season.points_per_game <= 24` |
| `margin_quality` | Positive margin meets negative margin | spread | `team.history.current_season.margin_per_game >= 10`; `opponent.history.current_season.margin_per_game < 0` |
| `yards_mismatch` | High yardage offense faces yardage leakage | spread | `team.history.current_season.total_yards_per_game >= 450`; `opponent.history.current_season.yards_allowed_per_game >= 400` |
| `efficiency_mismatch` | Efficient offense faces inefficient defense | spread | `team.history.current_season.yards_per_play >= 6.5`; `opponent.history.current_season.opponent_yards_per_play >= 6` |
| `pass_attack` | Productive passing meets permissive pass defense | spread | `team.history.current_season.passing_yards_per_game >= 280`; `opponent.history.current_season.passing_yards_allowed_per_game >= 250` |
| `rush_attack` | Productive rushing meets permissive run defense | spread | `team.history.current_season.rushing_yards_per_game >= 200`; `opponent.history.current_season.rushing_yards_allowed_per_game >= 170` |
| `pass_efficiency` | Efficient passing faces a high allowance defense | spread | `team.history.current_season.passing_yards_per_attempt >= 8`; `opponent.history.current_season.opponent_yards_per_play >= 6` |
| `rush_efficiency` | Efficient rushing faces high rushing allowance | spread | `team.history.current_season.rushing_yards_per_attempt >= 5`; `opponent.history.current_season.rushing_yards_allowed_per_game >= 170` |
| `possession_security` | Low turnover offense meets low takeaway defense | spread | `team.history.current_season.turnovers_per_game <= 1`; `opponent.history.current_season.takeaways_per_game <= 1` |
| `takeaway_pressure` | Takeaway defense faces turnover prone offense | spread | `team.history.current_season.takeaways_per_game >= 2`; `opponent.history.current_season.turnovers_per_game >= 2` |
| `conversion_pressure` | Third down strength meets high yardage allowance | spread | `team.history.current_season.third_down_rate >= 0.45`; `opponent.history.current_season.yards_allowed_per_game >= 400` |
| `red_zone_finish` | Red zone scoring strength meets scoring leakage | spread | `team.history.current_season.red_zone_score_rate >= 0.9`; `opponent.history.current_season.points_allowed_per_game >= 28` |
| `drive_volume` | First down volume meets low takeaway defense | spread | `team.history.current_season.first_downs_per_game >= 24`; `opponent.history.current_season.takeaways_per_game <= 1` |
| `clean_offense` | Low penalties and few sacks support sustained offense | spread | `team.history.current_season.penalty_yards_per_game <= 40`; `team.history.current_season.sacks_allowed_per_game <= 1` |
| `disrupted_offense` | Frequent sacks combine with weak third downs | spread | `team.history.current_season.sacks_allowed_per_game >= 3`; `team.history.current_season.third_down_rate <= 0.35` |
| `empty_yards` | High yardage fails to produce scoring | spread | `team.history.current_season.total_yards_per_game >= 400`; `team.history.current_season.points_per_game <= 24` |
| `short_field_scoring` | Scoring exceeds modest yardage with takeaway support | spread | `team.history.current_season.points_per_game >= 35`; `team.history.current_season.total_yards_per_game <= 350`; `team.history.current_season.takeaways_per_game >= 2` |
| `balanced_attack` | Both passing and rushing production are substantial | spread | `team.history.current_season.passing_yards_per_game >= 250`; `team.history.current_season.rushing_yards_per_game >= 180` |
| `one_dimensional_pass` | Passing production exceeds rushing by a wide margin | spread | `team.history.current_season.passing_yards_per_game >= 300`; `team.history.current_season.rushing_yards_per_game <= 100` |
| `one_dimensional_rush` | Rushing production leads a limited pass attack | spread | `team.history.current_season.rushing_yards_per_game >= 230`; `team.history.current_season.passing_yards_per_game <= 160` |
| `accuracy_without_depth` | High completion rate coexists with short passing output | spread | `team.history.current_season.completion_rate >= 0.7`; `team.history.current_season.passing_yards_per_attempt <= 6.5` |
| `vertical_inaccuracy` | Low completion rate coexists with high yards per attempt | spread | `team.history.current_season.completion_rate <= 0.55`; `team.history.current_season.passing_yards_per_attempt >= 8` |
| `run_stop_test` | Strong run prevention meets run heavy production | spread | `team.history.current_season.rushing_yards_allowed_per_game <= 110`; `opponent.history.current_season.rushing_yards_per_game >= 200` |
| `pass_stop_test` | Strong pass prevention meets pass heavy production | spread | `team.history.current_season.passing_yards_allowed_per_game <= 180`; `opponent.history.current_season.passing_yards_per_game >= 280` |
| `low_efficiency_defense` | Defensive efficiency exceeds scoring results | spread | `team.history.current_season.opponent_yards_per_play <= 5`; `team.history.current_season.points_allowed_per_game >= 28` |
| `bend_no_break` | High yardage allowance coexists with low scoring allowance | spread | `team.history.current_season.yards_allowed_per_game >= 400`; `team.history.current_season.points_allowed_per_game <= 20` |
| `fourth_down_reliance` | Fourth down success offsets poor third down conversion | spread | `team.history.current_season.fourth_down_rate >= 0.65`; `team.history.current_season.third_down_rate <= 0.35` |
| `red_zone_waste` | Weak red zone conversion despite strong drive volume | spread | `team.history.current_season.red_zone_score_rate <= 0.7`; `team.history.current_season.first_downs_per_game >= 24` |
| `discipline_gap` | Penalty burden differs materially across opponents | spread | `team.history.current_season.penalty_yards_per_game <= 40`; `opponent.history.current_season.penalty_yards_per_game >= 70` |

## Recent Form (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `recent_points_per_game` | Recent scoring production improves over prior season | spread | `team.history.last3.points_per_game > team.history.prior_season.points_per_game` |
| `recent_points_allowed_per_game` | Recent scoring allowance improves over prior season | spread | `team.history.last3.points_allowed_per_game < team.history.prior_season.points_allowed_per_game` |
| `recent_margin_per_game` | Recent scoring margin improves over prior season | spread | `team.history.last3.margin_per_game > team.history.prior_season.margin_per_game` |
| `recent_total_yards_per_game` | Recent offensive yardage improves over prior season | spread | `team.history.last3.total_yards_per_game > team.history.prior_season.total_yards_per_game` |
| `recent_yards_allowed_per_game` | Recent defensive yardage allowance improves over prior season | spread | `team.history.last3.yards_allowed_per_game < team.history.prior_season.yards_allowed_per_game` |
| `recent_passing_yards_per_game` | Recent passing volume improves over prior season | spread | `team.history.last3.passing_yards_per_game > team.history.prior_season.passing_yards_per_game` |
| `recent_rushing_yards_per_game` | Recent rushing volume improves over prior season | spread | `team.history.last3.rushing_yards_per_game > team.history.prior_season.rushing_yards_per_game` |
| `recent_yards_per_play` | Recent offensive efficiency improves over prior season | spread | `team.history.last3.yards_per_play > team.history.prior_season.yards_per_play` |
| `recent_opponent_yards_per_play` | Recent defensive efficiency improves over prior season | spread | `team.history.last3.opponent_yards_per_play < team.history.prior_season.opponent_yards_per_play` |
| `recent_passing_yards_per_attempt` | Recent passing efficiency improves over prior season | spread | `team.history.last3.passing_yards_per_attempt > team.history.prior_season.passing_yards_per_attempt` |
| `recent_rushing_yards_per_attempt` | Recent rushing efficiency improves over prior season | spread | `team.history.last3.rushing_yards_per_attempt > team.history.prior_season.rushing_yards_per_attempt` |
| `recent_completion_rate` | Recent completion accuracy improves over prior season | spread | `team.history.last3.completion_rate > team.history.prior_season.completion_rate` |
| `recent_third_down_rate` | Recent third down conversion improves over prior season | spread | `team.history.last3.third_down_rate > team.history.prior_season.third_down_rate` |
| `recent_red_zone_score_rate` | Recent red zone conversion improves over prior season | spread | `team.history.last3.red_zone_score_rate > team.history.prior_season.red_zone_score_rate` |
| `recent_turnovers_per_game` | Recent ball security improves over prior season | spread | `team.history.last3.turnovers_per_game < team.history.prior_season.turnovers_per_game` |
| `recent_takeaways_per_game` | Recent takeaway generation improves over prior season | spread | `team.history.last3.takeaways_per_game > team.history.prior_season.takeaways_per_game` |
| `recent_sacks_allowed_per_game` | Recent pass protection improves over prior season | spread | `team.history.last3.sacks_allowed_per_game < team.history.prior_season.sacks_allowed_per_game` |
| `recent_penalty_yards_per_game` | Recent penalty discipline improves over prior season | spread | `team.history.last3.penalty_yards_per_game < team.history.prior_season.penalty_yards_per_game` |
| `recent_first_downs_per_game` | Recent drive generation improves over prior season | spread | `team.history.last3.first_downs_per_game > team.history.prior_season.first_downs_per_game` |
| `recent_fourth_down_rate` | Recent fourth down conversion improves over prior season | spread | `team.history.last3.fourth_down_rate > team.history.prior_season.fourth_down_rate` |

## Venue (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `venue_points_per_game` | Scoring travels | spread | `team.history.venue.points_per_game > team.history.current_season.points_per_game` |
| `venue_points_allowed_per_game` | Scoring defense travels | spread | `team.history.venue.points_allowed_per_game < team.history.current_season.points_allowed_per_game` |
| `venue_margin_per_game` | Venue margin exceeds overall margin | spread | `team.history.venue.margin_per_game > team.history.current_season.margin_per_game` |
| `venue_total_yards_per_game` | Venue offensive production exceeds overall | spread | `team.history.venue.total_yards_per_game > team.history.current_season.total_yards_per_game` |
| `venue_yards_allowed_per_game` | Venue yardage prevention exceeds overall | spread | `team.history.venue.yards_allowed_per_game < team.history.current_season.yards_allowed_per_game` |
| `venue_yards_per_play` | Venue offensive efficiency exceeds overall | spread | `team.history.venue.yards_per_play > team.history.current_season.yards_per_play` |
| `venue_opponent_yards_per_play` | Venue defensive efficiency exceeds overall | spread | `team.history.venue.opponent_yards_per_play < team.history.current_season.opponent_yards_per_play` |
| `venue_turnover_margin_per_game` | Venue turnover margin exceeds overall | spread | `team.history.venue.turnover_margin_per_game > team.history.current_season.turnover_margin_per_game` |
| `venue_third_down_rate` | Venue third down conversion exceeds overall | spread | `team.history.venue.third_down_rate > team.history.current_season.third_down_rate` |
| `venue_red_zone_score_rate` | Venue red zone conversion exceeds overall | spread | `team.history.venue.red_zone_score_rate > team.history.current_season.red_zone_score_rate` |
| `road_ats` | Road visitor has covered a majority of prior away lines | spread | `context.home == false`; `team.history.away.ats_cover_rate > 0.5` |
| `home_ats` | Home host has failed a majority of prior home lines | spread | `context.home == true`; `team.history.home.ats_cover_rate < 0.5` |
| `neutral_experience` | Neutral game with positive neutral site margins | spread | `context.neutral == true`; `team.history.neutral.margin_per_game > 0` |
| `conference_ats` | Conference game with positive conference ATS record | spread | `context.conference == true`; `team.history.conference.ats_cover_rate > 0.5` |
| `nonconference_ats` | Nonconference game with negative nonconference ATS record | spread | `context.conference == false`; `team.history.nonconference.ats_cover_rate < 0.5` |
| `conference_scoring` | Conference scoring exceeds nonconference scoring | spread | `context.conference == true`; `team.history.conference.points_per_game > team.history.nonconference.points_per_game` |
| `road_defense` | Visitor allows more away than home scoring | spread | `context.home == false`; `team.history.away.points_allowed_per_game > team.history.home.points_allowed_per_game` |
| `home_offense` | Host generates more home than away yardage | spread | `context.home == true`; `team.history.home.total_yards_per_game > team.history.away.total_yards_per_game` |
| `neutral_defense` | Neutral site defense allows fewer points than overall | spread | `context.neutral == true`; `team.history.neutral.points_allowed_per_game < team.history.current_season.points_allowed_per_game` |
| `conference_turnovers` | Conference turnover margin exceeds nonconference | spread | `context.conference == true`; `team.history.conference.turnover_margin_per_game > team.history.nonconference.turnover_margin_per_game` |

## Schedule (16)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `short_rest_protection` | Short rest exposes frequent sacks | spread | `context.rest_days < 6`; `team.history.current_season.sacks_allowed_per_game >= 3` |
| `short_rest_rush` | Short rest for a run oriented offense | spread | `context.rest_days < 6`; `team.history.current_season.rushing_yards_per_game >= 200` |
| `short_rest_defense` | Short rest for a defense allowing sustained drives | spread | `context.rest_days < 6`; `team.history.current_season.yards_allowed_per_game >= 400` |
| `rest_advantage_accuracy` | Rest advantage supports an accurate passing offense | spread | `context.rest_days > context.opponent_rest_days`; `team.history.current_season.completion_rate >= 0.65` |
| `rest_disadvantage_turnovers` | Rest disadvantage with poor ball security | spread | `context.rest_days < context.opponent_rest_days`; `team.history.current_season.turnovers_per_game >= 2` |
| `bye_new_passer` | Extra preparation with a changed primary passer | spread | `context.rest_days >= 12`; `team.personnel.qb_changed == true` |
| `bye_defense` | Extra preparation for a weak efficiency defense | spread | `context.rest_days >= 12`; `team.history.current_season.opponent_yards_per_play >= 6` |
| `bye_coach_change` | Extra preparation under a new head coach | spread | `context.rest_days >= 12`; `team.personnel.coach_changed == true` |
| `road_new_passer` | Road game with changed observed primary passer | spread | `context.home == false`; `team.personnel.qb_changed == true` |
| `road_poor_accuracy` | Road game with low passing accuracy | spread | `context.home == false`; `team.history.current_season.completion_rate <= 0.55` |
| `road_clean_play` | Road game with few penalties and turnovers | spread | `context.home == false`; `team.history.current_season.penalty_yards_per_game <= 40`; `team.history.current_season.turnovers_per_game <= 1` |
| `early_returning` | Early season with substantial returning usage | spread | `context.week <= 3`; `team.personnel.returning_usage >= 0.65` |
| `late_low_depth_proxy` | Late season with substantial stored injury adjustment | spread | `context.week >= 10`; `team.metrics.injury_total_adjustment <= -5` |
| `opponent_bye` | Opponent has extra preparation and efficient offense | spread | `context.opponent_rest_days >= 12`; `opponent.history.current_season.yards_per_play >= 6.5` |
| `neutral_new_coach` | New head coach at a neutral site | spread | `context.neutral == true`; `team.personnel.coach_changed == true` |
| `season_opener_qb` | Season opener with changed observed passer | spread | `context.week <= 1`; `team.personnel.qb_changed == true` |

## Weather (4)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `wind_pass_volume` | Wind challenges a high volume passing offense | spread | `context.wind_speed_mph >= 20`; `team.history.current_season.passing_yards_per_game >= 280` |
| `rain_ball_security` | Precipitation compounds turnover risk | spread | `context.precipitation_inches >= 0.1`; `team.history.current_season.turnovers_per_game >= 2` |
| `cold_accuracy` | Cold conditions test an inaccurate passing offense | spread | `context.temperature_f <= 35`; `team.history.current_season.completion_rate <= 0.55` |
| `gusts_total` | Strong gusts accompany two passing offenses | total | `context.wind_gust_mph >= 30`; `team.history.current_season.passing_yards_per_game >= 250`; `opponent.history.current_season.passing_yards_per_game >= 250` |

## Personnel (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `returning_passing` | Returning passing usage supports efficient passing | spread | `team.personnel.returning_passing_usage >= 0.65`; `team.history.current_season.passing_yards_per_attempt >= 8` |
| `returning_rushing` | Returning rushing usage supports productive rushing | spread | `team.personnel.returning_rushing_usage >= 0.65`; `team.history.current_season.rushing_yards_per_game >= 200` |
| `returning_receiving` | Returning receiving usage with high passing volume | spread | `team.personnel.returning_receiving_usage >= 0.65`; `team.history.current_season.passing_yards_per_game >= 280` |
| `low_returning_high_rating` | Low returning usage contrasts with strong FPI | spread | `team.personnel.returning_usage < 0.35`; `team.metrics.fpi >= 15` |
| `new_passer_strong_prior` | Changed passer follows strong prior passing efficiency | spread | `team.personnel.qb_changed == true`; `team.history.prior_season.passing_yards_per_attempt >= 8` |
| `new_passer_sacks` | Changed passer faces poor pass protection | spread | `team.personnel.qb_changed == true`; `team.history.current_season.sacks_allowed_per_game >= 3` |
| `same_passer_accuracy` | Continuing observed passer with high accuracy | spread | `team.personnel.qb_changed == false`; `team.history.current_season.completion_rate >= 0.65` |
| `same_passer_turnovers` | Continuing observed passer with turnover concerns | spread | `team.personnel.qb_changed == false`; `team.history.current_season.turnovers_per_game >= 2` |
| `new_coach_low_returning` | New head coach with low returning usage | spread | `team.personnel.coach_changed == true`; `team.personnel.returning_usage < 0.35` |
| `new_coach_poor_prior` | New head coach follows negative prior margins | spread | `team.personnel.coach_changed == true`; `team.history.prior_season.margin_per_game < 0` |
| `coach_stability_low_penalties` | Stable head coach with low penalties | spread | `team.personnel.coach_changed == false`; `team.history.current_season.penalty_yards_per_game <= 40` |
| `talent_scoring_gap` | Superior talent meets inferior current scoring | spread | `team.personnel.talent > opponent.personnel.talent`; `team.history.current_season.points_per_game < opponent.history.current_season.points_per_game` |
| `talent_efficiency_gap` | Superior talent meets inferior current efficiency | spread | `team.personnel.talent > opponent.personnel.talent`; `team.history.current_season.yards_per_play < opponent.history.current_season.yards_per_play` |
| `recruiting_returning` | Better recruiting rank combines with returning usage | spread | `team.personnel.recruiting_rank < opponent.personnel.recruiting_rank`; `team.personnel.returning_usage >= 0.65` |
| `recruiting_coach_change` | Strong recruiting rank under a new coach | spread | `team.personnel.recruiting_rank <= 20`; `team.personnel.coach_changed == true` |
| `talent_road` | More talented visitor faces a home opponent | spread | `context.home == false`; `team.personnel.talent > opponent.personnel.talent` |
| `returning_defense_test` | High returning usage faces efficient opposing offense | spread | `team.personnel.returning_usage >= 0.65`; `opponent.history.current_season.yards_per_play >= 6.5` |
| `qb_changes_both` | Both teams changed observed primary passers | spread | `team.personnel.qb_changed == true`; `opponent.personnel.qb_changed == true` |
| `coaches_changes_both` | Both teams have changed head coaches | spread | `team.personnel.coach_changed == true`; `opponent.personnel.coach_changed == true` |
| `unrated_transfers_qb` | Unrated transfer coverage coexists with changed passer | spread | `team.personnel.unrated_transfers > 0`; `team.personnel.qb_changed == true` |

## Market Spread (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `big_favorite_defense` | Three touchdown favorite has strong scoring defense | spread | `context.spread <= -21`; `team.history.current_season.points_allowed_per_game <= 20` |
| `big_favorite_turnovers` | Three touchdown favorite has turnover risk | spread | `context.spread <= -21`; `team.history.current_season.turnovers_per_game >= 2` |
| `big_favorite_run` | Three touchdown favorite relies on rushing production | spread | `context.spread <= -21`; `team.history.current_season.rushing_yards_per_game >= 230` |
| `big_dog_efficiency` | Three touchdown underdog retains offensive efficiency | spread | `context.spread >= 21`; `team.history.current_season.yards_per_play >= 6` |
| `big_dog_ball_security` | Three touchdown underdog protects possession | spread | `context.spread >= 21`; `team.history.current_season.turnovers_per_game <= 1` |
| `short_favorite_red_zone` | Field goal favorite has weak red zone scoring | spread | `context.spread < 0`; `context.spread >= -3`; `team.history.current_season.red_zone_score_rate <= 0.7` |
| `short_dog_takeaways` | Field goal underdog generates takeaways | spread | `context.spread > 0`; `context.spread <= 3`; `team.history.current_season.takeaways_per_game >= 2` |
| `home_dog_ats` | Home underdog has positive recent ATS record | spread | `context.home == true`; `context.spread > 0`; `team.history.last3.ats_cover_rate > 0.5` |
| `road_favorite_ats` | Road favorite has negative away ATS record | spread | `context.home == false`; `context.spread < 0`; `team.history.away.ats_cover_rate < 0.5` |
| `winning_not_covering` | Winning record coexists with losing ATS record | spread | `team.history.current_season.win_rate > 0.5`; `team.history.current_season.ats_cover_rate < 0.5` |
| `losing_covering` | Losing record coexists with winning ATS record | spread | `team.history.current_season.win_rate < 0.5`; `team.history.current_season.ats_cover_rate > 0.5` |
| `ats_without_efficiency` | Recent covers coexist with poor offensive efficiency | spread | `team.history.last3.ats_cover_rate > 0.5`; `team.history.last3.yards_per_play < 5` |
| `ats_with_takeaways` | Recent covers coexist with positive turnover margin | spread | `team.history.last3.ats_cover_rate > 0.5`; `team.history.last3.turnover_margin_per_game >= 1` |
| `noncovers_improving_yards` | Recent noncovers coexist with improving yardage | spread | `team.history.last3.ats_cover_rate < 0.5`; `team.history.last3.total_yards_per_game > team.history.prior_season.total_yards_per_game` |
| `favorite_injuries` | Favorite has substantial injury adjustment | spread | `context.spread < 0`; `team.metrics.injury_total_adjustment <= -5` |
| `underdog_rating_edge` | Underdog has higher FPI than opponent | spread | `context.spread > 0`; `team.metrics.fpi > opponent.metrics.fpi` |
| `favorite_rating_deficit` | Favorite has lower Elo than opponent | spread | `context.spread < 0`; `team.elo < opponent.elo` |
| `key_seven` | Seven point line with close historical margins | spread | `context.spread == -7`; `team.history.current_season.margin_per_game <= 7` |
| `key_three` | Three point underdog with strong red zone scoring | spread | `context.spread == 3`; `team.history.current_season.red_zone_score_rate >= 0.9` |
| `huge_line_low_total` | Large favorite in a low total market | spread | `context.spread <= -21`; `context.total <= 45` |

## Total (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `both_fast_proxy` | Both teams have high play attempt volume | total | `team.late.plays >= 75`; `opponent.late.plays >= 75` |
| `both_low_volume` | Both teams have low play attempt volume | total | `team.late.plays <= 60`; `opponent.late.plays <= 60` |
| `two_pass_attacks` | Two high volume passing offenses meet | total | `team.history.current_season.passing_yards_per_game >= 280`; `opponent.history.current_season.passing_yards_per_game >= 280` |
| `two_run_attacks` | Two high volume rushing offenses meet | total | `team.history.current_season.rushing_yards_per_game >= 200`; `opponent.history.current_season.rushing_yards_per_game >= 200` |
| `two_efficient_attacks` | Both offenses gain efficiently | total | `team.history.current_season.yards_per_play >= 6.5`; `opponent.history.current_season.yards_per_play >= 6.5` |
| `two_efficient_defenses` | Both defenses limit yards per play | total | `team.history.current_season.opponent_yards_per_play <= 5`; `opponent.history.current_season.opponent_yards_per_play <= 5` |
| `red_zone_both` | Both offenses convert red zone opportunities | total | `team.history.current_season.red_zone_score_rate >= 0.9`; `opponent.history.current_season.red_zone_score_rate >= 0.9` |
| `red_zone_stalls` | Both offenses struggle in red zone | total | `team.history.current_season.red_zone_score_rate <= 0.7`; `opponent.history.current_season.red_zone_score_rate <= 0.7` |
| `third_down_both` | Both offenses sustain third downs | total | `team.history.current_season.third_down_rate >= 0.45`; `opponent.history.current_season.third_down_rate >= 0.45` |
| `turnovers_both` | Both offenses frequently lose possession | total | `team.history.current_season.turnovers_per_game >= 2`; `opponent.history.current_season.turnovers_per_game >= 2` |
| `clean_possessions` | Both offenses protect possession | total | `team.history.current_season.turnovers_per_game <= 1`; `opponent.history.current_season.turnovers_per_game <= 1` |
| `sacks_both` | Both offenses allow frequent sacks | total | `team.history.current_season.sacks_allowed_per_game >= 3`; `opponent.history.current_season.sacks_allowed_per_game >= 3` |
| `penalties_both` | Both teams incur substantial penalties | total | `team.history.current_season.penalty_yards_per_game >= 70`; `opponent.history.current_season.penalty_yards_per_game >= 70` |
| `high_total_low_efficiency` | High market total meets two inefficient offenses | total | `context.total >= 60`; `team.history.current_season.yards_per_play <= 5`; `opponent.history.current_season.yards_per_play <= 5` |
| `low_total_high_efficiency` | Low market total meets two efficient offenses | total | `context.total <= 45`; `team.history.current_season.yards_per_play >= 6`; `opponent.history.current_season.yards_per_play >= 6` |
| `recent_overs_defense` | Recent overs coincide with weak defensive efficiency | total | `team.history.last3.over_rate > 0.5`; `team.history.last3.opponent_yards_per_play >= 6` |
| `recent_unders_offense` | Recent unders coincide with weak offensive efficiency | total | `team.history.last3.under_rate > 0.5`; `team.history.last3.yards_per_play <= 5` |
| `conference_under` | Conference game with historical conference unders | total | `context.conference == true`; `team.history.conference.under_rate > 0.5` |
| `neutral_over` | Neutral site game with historical neutral overs | total | `context.neutral == true`; `team.history.neutral.over_rate > 0.5` |
| `short_rest_both` | Both teams play on short rest | total | `context.rest_days < 6`; `context.opponent_rest_days < 6` |

## Rating Context (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `fpi_elo_disagree` | FPI advantage conflicts with Elo deficit | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.elo < opponent.elo` |
| `power_fpi_disagree` | Power rating advantage conflicts with FPI deficit | spread | `team.metrics.power_rating > opponent.metrics.power_rating`; `team.metrics.fpi < opponent.metrics.fpi` |
| `rating_margin_disagree` | Rating advantage conflicts with scoring margin deficit | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.history.current_season.margin_per_game < opponent.history.current_season.margin_per_game` |
| `rating_efficiency_disagree` | Rating advantage conflicts with offensive efficiency deficit | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.history.current_season.yards_per_play < opponent.history.current_season.yards_per_play` |
| `rating_defense_disagree` | Rating advantage conflicts with defensive efficiency deficit | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.history.current_season.opponent_yards_per_play > opponent.history.current_season.opponent_yards_per_play` |
| `elite_fpi_new_qb` | High FPI team changed observed primary passer | spread | `team.metrics.fpi >= 20`; `team.personnel.qb_changed == true` |
| `elite_fpi_new_coach` | High FPI team changed head coach | spread | `team.metrics.fpi >= 20`; `team.personnel.coach_changed == true` |
| `negative_fpi_wins` | Negative FPI team has winning record | spread | `team.metrics.fpi < 0`; `team.history.current_season.win_rate > 0.5` |
| `positive_fpi_losses` | Positive FPI team has losing record | spread | `team.metrics.fpi > 0`; `team.history.current_season.win_rate < 0.5` |
| `prior_strength_current_decline` | Strong prior margin meets negative current margin | spread | `team.history.prior_season.margin_per_game >= 10`; `team.history.current_season.margin_per_game < 0` |
| `prior_weak_current_growth` | Weak prior margin meets strong current margin | spread | `team.history.prior_season.margin_per_game < 0`; `team.history.current_season.margin_per_game >= 10` |
| `rating_growth_returning` | FPI improved over prior metric with returning usage | spread | `team.metrics.fpi > team.prior_metrics.fpi`; `team.personnel.returning_usage >= 0.65` |
| `rating_growth_turnovers` | FPI improvement coexists with turnover fortune | spread | `team.metrics.fpi > team.prior_metrics.fpi`; `team.history.current_season.turnover_margin_per_game >= 2` |
| `rating_decline_qb` | FPI decline coexists with passer change | spread | `team.metrics.fpi < team.prior_metrics.fpi`; `team.personnel.qb_changed == true` |
| `form_rating_efficiency` | Positive recent form rating aligns with efficient offense | spread | `team.metrics.recent_form_rating > 0`; `team.history.last3.yards_per_play >= 6.5` |
| `form_rating_defense` | Negative recent form aligns with weak defense | spread | `team.metrics.recent_form_rating < 0`; `team.history.last3.opponent_yards_per_play >= 6` |
| `injury_rating_pass` | Injury adjustment combines with poor passing efficiency | spread | `team.metrics.injury_total_adjustment <= -5`; `team.history.current_season.passing_yards_per_attempt <= 6` |
| `fatigue_rush_defense` | Positive fatigue burden combines with run defense leakage | spread | `team.metrics.rest_travel_fatigue > 0`; `team.history.current_season.rushing_yards_allowed_per_game >= 170` |
| `rating_edge_low_talent` | Rating advantage despite lower talent | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.personnel.talent < opponent.personnel.talent` |
| `rating_edge_low_returning` | Rating advantage despite lower returning usage | spread | `team.metrics.fpi > opponent.metrics.fpi`; `team.personnel.returning_usage < opponent.personnel.returning_usage` |

## Late Game (20)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `late_allowance_favorite` | Large favorite allows substantial fourth quarter scoring | spread | `context.spread <= -14`; `team.late.fourth_allowed >= 10` |
| `late_margin_favorite` | Large favorite has negative fourth quarter margins | spread | `context.spread <= -14`; `team.late.fourth_margin < 0` |
| `lead_erosion` | Large favorite loses margin after entering fourth up twenty | spread | `context.spread <= -20`; `team.late.leading_fourth_margin < 0` |
| `lead_extension` | Efficient offense extends twenty point fourth quarter leads | spread | `team.history.current_season.yards_per_play >= 6.5`; `team.late.leading_fourth_margin > 0` |
| `late_dog_offense` | Large underdog has positive fourth quarter margins | spread | `context.spread >= 14`; `team.late.fourth_margin > 0` |
| `late_defense_low_volume` | Low attempt volume combines with late scoring allowance | spread | `team.late.plays <= 60`; `team.late.fourth_allowed >= 10` |
| `late_defense_takeaways` | Strong takeaway rate coexists with late defensive leakage | spread | `team.history.current_season.takeaways_per_game >= 2`; `team.late.fourth_allowed >= 10` |
| `late_drop_high_margin` | Strong overall margins coexist with late margin losses | spread | `team.history.current_season.margin_per_game >= 14`; `team.late.fourth_margin < 0` |
| `late_strength_low_margin` | Weak overall margins coexist with late margin gains | spread | `team.history.current_season.margin_per_game <= 0`; `team.late.fourth_margin > 0` |
| `high_volume_sacks` | High attempt volume combines with frequent sacks | spread | `team.late.plays >= 75`; `team.history.current_season.sacks_allowed_per_game >= 3` |
| `high_volume_turnovers` | High attempt volume combines with turnovers | spread | `team.late.plays >= 75`; `team.history.current_season.turnovers_per_game >= 2` |
| `low_volume_efficiency` | Low attempt volume masks strong offensive efficiency | spread | `team.late.plays <= 60`; `team.history.current_season.yards_per_play >= 6.5` |
| `high_volume_low_efficiency` | High attempt volume masks weak offensive efficiency | spread | `team.late.plays >= 75`; `team.history.current_season.yards_per_play <= 5` |
| `late_both_allow` | Both defenses allow substantial fourth quarter scoring | total | `team.late.fourth_allowed >= 10`; `opponent.late.fourth_allowed >= 10` |
| `late_protection` | Late margin losses coincide with poor pass protection | spread | `team.late.fourth_margin < 0`; `team.history.current_season.sacks_allowed_per_game >= 3` |
| `late_discipline` | Late margin losses coincide with high penalties | spread | `team.late.fourth_margin < 0`; `team.history.current_season.penalty_yards_per_game >= 70` |
| `late_rushing` | Late margin gains coexist with productive rushing | spread | `team.late.fourth_margin > 0`; `team.history.current_season.rushing_yards_per_game >= 200` |
| `late_new_passer` | Changed primary passer with negative fourth quarter margins | spread | `team.personnel.qb_changed == true`; `team.late.fourth_margin < 0` |
| `late_short_rest` | Short rest follows weak fourth quarter defense | spread | `context.rest_days < 6`; `team.late.fourth_allowed >= 10` |
| `late_road_favorite` | Road favorite has weak fourth quarter margins | spread | `context.home == false`; `context.spread < 0`; `team.late.fourth_margin < 0` |

## Interaction (10)

| ID | Signal | Market | All required conditions |
| --- | --- | --- | --- |
| `rush_protection_escape` | Strong rushing provides an alternative to weak pass protection | spread | `team.history.current_season.rushing_yards_per_attempt >= 5`; `team.history.current_season.sacks_allowed_per_game >= 3` |
| `pass_third_down_gap` | Efficient passing does not translate to third downs | spread | `team.history.current_season.passing_yards_per_attempt >= 8`; `team.history.current_season.third_down_rate <= 0.35` |
| `completion_red_zone_gap` | Accurate passing does not translate to red zone scoring | spread | `team.history.current_season.completion_rate >= 0.7`; `team.history.current_season.red_zone_score_rate <= 0.7` |
| `defense_turnover_dependency` | Poor defensive yardage accompanies high takeaways | spread | `team.history.current_season.yards_allowed_per_game >= 400`; `team.history.current_season.takeaways_per_game >= 2` |
| `rush_defense_pass_exposure` | Strong run defense coexists with pass vulnerability | spread | `team.history.current_season.rushing_yards_allowed_per_game <= 110`; `team.history.current_season.passing_yards_allowed_per_game >= 280` |
| `pass_defense_run_exposure` | Strong pass defense coexists with run vulnerability | spread | `team.history.current_season.passing_yards_allowed_per_game <= 180`; `team.history.current_season.rushing_yards_allowed_per_game >= 200` |
| `drives_without_firstdowns` | Scoring production coexists with low first down volume | spread | `team.history.current_season.points_per_game >= 35`; `team.history.current_season.first_downs_per_game <= 18` |
| `turnover_reversal_risk` | Winning record depends on positive turnovers despite negative yardage profile | spread | `team.history.current_season.win_rate > 0.5`; `team.history.current_season.turnover_margin_per_game >= 2`; `team.history.current_season.total_yards_per_game < team.history.current_season.yards_allowed_per_game` |
| `schedule_defense_shift` | Conference defensive allowance exceeds prior season baseline | spread | `context.conference == true`; `team.history.conference.points_allowed_per_game > team.history.prior_season.points_allowed_per_game` |
| `opponent_accuracy_pressure` | Opponent accuracy tests a defense allowing high completion yardage | spread | `opponent.history.current_season.completion_rate >= 0.7`; `team.history.current_season.passing_yards_allowed_per_game >= 250` |

## Production integration and data repair

Release 1.6.0 freezes these definitions with its configuration. The snapshot builder captures historical evidence, advanced metrics, weather, rest and last-stored pregame quotes. The calculator evaluates the rules for each team and reports triggers, missing inputs, fitted contributions and resulting spread/total adjustments. Existing forecast output stays intact when no supported correction is available; this does not add a bet hold.

The residual learner uses one eligible, published pregame forecast per completed game, with the same baseline configuration and outcomes observed before capture. It fits on earlier dates and checks later dates, requiring 30 training and 15 validation games per condition. Corrections shrink toward zero, must improve held-out absolute error beyond the multiple-comparison screen, are averaged within and across families, and are capped at two spread points or three total points. This is a residual-model check, not calibrated cover probability or proven profitability. A condition can trigger while its coefficient remains unavailable.

`cfb:report-football-signals --season=2026 --catalog` lists definitions and actual coverage/contributions. The daily 03:30 report tracks allocated contribution error reductions after results arrive. This holds the other frozen contributions fixed; it is not causal attribution.

The feed fixes serialize CFBD boolean query values correctly, parse `epa.total` and `epaAllowed.total` for WEPA, preserve season-specific lookup indices, and surface request failures before overwriting metrics. ESPN sacks allowed use the opposing defense's explicit aggregate sacks when the team-stat field is absent. Sack rate requires complete sacks and attempts for the eligible team sample. An empty provider season remains missing data.

Weather records distinguish the forecast's valid hour from when it was received. Forecast selection uses the canonical kickoff instant in UTC; the existing pregame pipeline refreshes weather without making an unavailable weather feed block predictions.

For completed prior seasons, CFBD season `sacksOpponent`, `passAttempts`, and `games` provide a validated aggregate fallback for sack rate. The frozen season mean can support the prior-season sack comparison; it does not invent per-game sack values. `CalculateTeamMetrics::refreshExternalMetrics` refreshes the external fields without rescanning historical plays or changing local ratings.
