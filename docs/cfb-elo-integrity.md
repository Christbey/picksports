# CFB Elo integrity and canonical release 1.5.0

CFB Elo model `cfb-elo-2.0.1` uses fresh locked team state, atomic paired updates, explicit starting ratings, per-team season initialization, and source-result fingerprints. It retains the existing expected-win and K-factor formula while repairing chronology and offseason application. The integer transfer is rounded once and applied equally and oppositely to both teams, preventing rounding-driven rating inflation. The default first available season starts at 1500; subsequent seasons regress toward 1500 using the configured factor. This is not a claim of calibrated betting accuracy.

## Deployment and repair

1. Apply the integrity migration. It preserves the existing rows and adds versioned active slots. Its rollback deliberately refuses to erase retained history.
2. Run `cfb:sync-season-affiliations --season=YYYY` for every season represented in completed games. The command uses season-specific CFBD membership, rejects ambiguous or incomplete mappings, and marks other local teams explicitly non-FBS. Eligibility reads never write affiliations.
3. Run `cfb:rebuild-elo`. Review its game/team coverage, integrity results, and rating differences. The candidate does not change active ratings.
4. Activate the reviewed candidate with `cfb:rebuild-elo --activate=ID`. Activation locks source rows, verifies their digest, archives old active rows without deleting them, and applies the candidate transactionally. A changed source requires a fresh candidate.
5. Refresh FPI with `--require-complete`, then recompute historical/current metrics. Historical metrics use their season's recorded Elo and do not use current injuries. Recomputed historical summaries remain retrospective, not proof of what was known at past kickoff times.
6. Register canonical release 1.5.0, atomically retire the previous approved CFB pregame release, and run `cfb:run-pregame-pipeline`. There must be exactly one approved pregame release. Preserve prior forecasts and their input snapshots.

The regular Elo command rejects legacy, incomplete, corrected, and out-of-order history rather than silently compounding it. Use candidate rebuild and review for those cases. `--reset` is disabled for CFB. Current-state consistency alone is not sufficient; verify pair completeness, fingerprints, continuity, and season initialization.

## Forecast evidence

Release 1.4.0 identifies the actual Elo source and version. It derives an auditable regressed prior-season baseline before a team's first eligible final game, and rejects stale/legacy/post-cutoff evidence. Snapshot capture and Elo activation share a lock. Missing verified Elo blocks eligibility.

FPI remains the primary spread baseline where both teams have it. Feature coverage diagnostics distinguish executed components from features present only in the legacy engine. An empty injury list does not prove health. The pregame pipeline stops on an unavailable or incomplete injury fetch, and recomputes metrics after Elo and injury updates. Spread/total markets no longer inherit moneyline confidence.

## Validation

`cfb:compare-frozen-baselines --season=YYYY` compares fixed FPI, corrected Elo, and 50/50 baseline margins using frozen pregame evidence. It reports paired sample counts, MAE and bias, not a calibrated ATS probability or automatic promotion. No comparable observations means insufficient evidence, not zero error. Retrospective reconstruction cannot satisfy the calibration trainer's pregame-availability gate.

Preserve the closing-line convention: the last stored pregame quote before kickoff. Do not overwrite immutable historical forecasts with rebuilt inputs or backdate newly imported evidence.

Release 1.4.1 also canonicalizes signed numeric zero before hashing, matching JSON database storage without accepting changed numeric values. Prior immutable drafts and successful calculation hashes are preserved; fresh release runs replace withheld drafts. CFB UI labels distinguish win-model confidence from cover probability.


## Release 1.5.0: six recommendation improvements

1. CFB odds ingestion requests DraftKings, FanDuel, and BetMGM. A bet requires at least two distinct configured books with paired sides, provider and storage freshness, a consistent future kickoff, acceptable American prices (-125 to +200), and at least three points of edge on the same side. Quotes are at most 60 minutes old. The chosen line is the best handicap among confirming quotes within price limits, with price breaking ties; it is not a claim to globally optimal EV across line/price tradeoffs.
2. Baseline and decision reports separate spreads below 20, 20–under28, 28–under35, and 35+. Cumulative 20+/28+/35+ counts are explicitly overlapping. New snapshots preserve prior-game pace proxies and regulation fourth-quarter scoring with source IDs and timestamps. These descriptive fields do not silently change margins or claim to measure garbage time.
3. Fixed FPI, corrected Elo, and half-blend comparisons use frozen contemporaneous evidence only. The daily report records MAE/bias and input coverage; it does not auto-promote a model or reconstruct past recommendations.
4. `cfb:train-spread-calibration --release-version=1.5.0` fits an empirical residual challenger only after 500 training, 200 validation, and 200 test observations with complete dates kept in chronological folds. Large-spread domains each require 100/50/50 examples and held-out Brier/ECE gates. American-odds EV treats pushes separately. Production inference requires an explicitly configured promoted artifact, exact release/configuration compatibility, validated domain, and prospective shadow approval. Missing evidence returns null probability/EV and a held candidate. Weekly candidate training never automatically promotes artifacts.
5. `cfb:sync-preseason-team-signals --season=2026 --include-coaches --require-data` imports returning production, transfers, talent, recruiting, and current/prior head coaches. Component-specific observation times and payload hashes prevent a partial refresh from freshening unrelated facts. New snapshots preserve this evidence and observed primary-passer changes across seasons, including departed quarterbacks. The early-season prior exception now requires complete personnel evidence. Coordinator continuity and confirmed starters are not invented. Personnel values are frozen covariates for validation; no unvalidated point adjustment is applied on top of FPI. `cfb:report-personnel-evidence --season=2026` exposes remaining coverage gaps.
6. Every freshly published CFB canonical revision freezes an immutable BetDecision (bet or hold), including release, snapshot, exact quote, book, line, price, and eligibility reasons. `cfb:record-canonical-bet-decisions --season=2026` can recover fresh records within 15 minutes; it will not backdate old recommendations. `cfb:report-frozen-decisions --season=2026` grades each recorded decision, separates held/counterfactual and tracking cohorts, and reports ATS, price-adjusted one-unit returns, and CLV. Revisions are correlated observations, not separate placed wagers. Missing prices stay missing.

Closing-line selection uses the last **stored** same-market/side pregame quote, ordered by `created_at`, then ID, with both capture and storage strictly before kickoff. This can use a different sportsbook than entry by the requested convention; the report exposes both. No consensus, post-kickoff observation, or later imported historical quote substitutes for that closing line.

Production rollout: apply the personnel evidence migration, refresh personnel and multi-book odds, register draft 1.5.0, atomically retire the prior approved release and approve 1.5.0, then run the guarded pregame pipeline. Preserve historical forecasts and decisions. Run the three reports and calibration command to disclose actual available evidence. Daily baseline/decision reports and weekly challenger training retain logs under `storage/logs/cfb-*` and use scheduler heartbeats.

Provider head-coach schema: https://api.collegefootballdata.com/api/coaches . This endpoint covers head coaches; no offensive/defensive coordinator coverage is implied.

Existing CFB settlements can be repaired with `sports:settle-bet-decisions --sport=cfb --regrade-cfb`; each change preserves the previous settlement in its audit metadata. The daily CFB settlement job uses this mode so corrected final scores remain reflected. Missing entry prices produce unknown (null) returns, excluded from ROI denominators; they are never treated as a zero-profit bet.
