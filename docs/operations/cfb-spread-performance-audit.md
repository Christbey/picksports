# CFB spread performance audit — October 10, 2026

The stored 75–80–2 spread record was reproduced by the frozen-decision grader with no grade mismatches. It comprises 157 verified prospective shadow decisions across 102 games, all Weeks 3 and 4, from releases 1.5.1, 1.6.0, 1.6.2 and 1.6.3. All entries were provisional, not qualified bets. The latest decision per game is 47–53–2. These counts are point-in-time audit results, not current-release performance or proof of real-money execution.

`cfb:report-frozen-decisions --season=2026` retains every revision for audit and now supplies one latest verified pregame decision per game/cohort/market, plus release-separated cohorts. Unknown prices are excluded from return denominators. Reports must distinguish all-revision diagnostics from game-level performance and current versus retired releases. Do not select profitable subgroups after observing outcomes and call them validated betting rules.

Settlement streams batches of 50 and retains the explicit processing limit. Missing prices no longer prevent recording a knowable outcome: monetary and shadow returns remain null and `exclude_from_return_metrics` is true. No entry prices, missing decisions or projections are reconstructed. Existing decisions and prior settlements remain intact unless explicit regrading is requested.

The frozen-baseline comparator sorts narrow snapshot IDs before loading JSON payloads, avoiding production MySQL sort-buffer exhaustion. It compares paired contemporaneous evidence only. Retrospective margin accuracy does not establish ATS or price-adjusted betting profitability. Spread calibration remains unapproved; no spread promotion, side reversal or recalibration is authorized by this audit alone.


## Exact-entry paired comparison

`cfb:compare-frozen-spread-entries --season=2026 --development-through-week=3` uses one latest canonical prospective decision per final FBS/unknown-membership game. It requires the original published prediction, a successful linked calculation run, the exact content-hash-matching verified snapshot, and source/prediction availability by the original decision time. It never substitutes a later pregame snapshot or today's team ratings. It compares official and fixed FPI-plus-2.2-home-field margins against the identical original market handicap with a fixed three-point diagnostic signal threshold.

Market confirmation now carries the opposite quote ID, snapshot ID, book, handicap, price, contract and observed/captured/stored timestamps in every accepted quote. The released decision's existing quote/evidence hash and immutable market snapshot automatically preserve that pair. The comparison prefers the frozen pair, validating its identity and timing. For older records it can use the same original odds snapshot, book and contract only when the opposite quote was stored by entry time and its observation/capture was fresh. Later imports are rejected even if backdated. No opposite-side price is inferred from the original side or from closing odds.

The report supplies overall signal W-L-P and price coverage plus a common price-supported cohort for return comparisons. Development and later-week populations are separated, but later-week results already inspected are explicitly retrospective, not untouched validation. Candidate signals do not satisfy betting calibration or other promotion gates merely by exceeding three points. Coefficients are fixed; no fitting, automatic promotion, publication rewrite or wager execution occurs.
