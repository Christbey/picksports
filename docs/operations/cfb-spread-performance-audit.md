# CFB spread performance audit — October 10, 2026

The stored 75–80–2 spread record was reproduced by the frozen-decision grader with no grade mismatches. It comprises 157 verified prospective shadow decisions across 102 games, all Weeks 3 and 4, from releases 1.5.1, 1.6.0, 1.6.2 and 1.6.3. All entries were provisional, not qualified bets. The latest decision per game is 47–53–2. These counts are point-in-time audit results, not current-release performance or proof of real-money execution.

`cfb:report-frozen-decisions --season=2026` retains every revision for audit and now supplies one latest verified pregame decision per game/cohort/market, plus release-separated cohorts. Unknown prices are excluded from return denominators. Reports must distinguish all-revision diagnostics from game-level performance and current versus retired releases. Do not select profitable subgroups after observing outcomes and call them validated betting rules.

Settlement streams batches of 50 and retains the explicit processing limit. Missing prices no longer prevent recording a knowable outcome: monetary and shadow returns remain null and `exclude_from_return_metrics` is true. No entry prices, missing decisions or projections are reconstructed. Existing decisions and prior settlements remain intact unless explicit regrading is requested.

The frozen-baseline comparator sorts narrow snapshot IDs before loading JSON payloads, avoiding production MySQL sort-buffer exhaustion. It compares paired contemporaneous evidence only. Retrospective margin accuracy does not establish ATS or price-adjusted betting profitability. Spread calibration remains unapproved; no spread promotion, side reversal or recalibration is authorized by this audit alone.
