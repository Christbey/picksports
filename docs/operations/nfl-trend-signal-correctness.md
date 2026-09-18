# NFL trend and signal correctness

## Contracts

- NFL `predicted_spread` and analysis `market_spread` use home-minus-away margin: positive favors home. A book's home handicap is its negative. A spread pick follows the sign of `model margin - market margin`, not the outright winner.
- Model-margin history is labeled as model history, never market ATS. Pushes and missing predictions interrupt model streaks. Ties are retained in W-L-T records.
- Favorable spread CLV is `closing home margin - entry home margin` for home picks, the reverse for away picks.
- Winner-strength ranking uses the picked side's probability. These ranks are not calibrated hit probabilities.
- Newly generated pro-signal payloads are versioned `nfl-pro-signal-layer-v2`; old stored payloads are not relabeled or rewritten.
- The signals API selects the earliest future scheduled regular-season/postseason week in the requested season, then only scheduled games in that week and phase. Final, live, and already-started scheduled games are excluded. Selection uses UTC date plus clock. An empty eligible schedule returns no recommendations.
- `winners`, `covers`, and `week` are the rolling-slate fields. Compatibility `week_one_*` arrays are empty after Week 1. Winner/cover arrays are contextual; only explicit `bet` classifications enter `recommended_bets`; other eligible classifications enter `watchlist`.
- Spread and total recommendations carry the edge's direction and matching market/line convention. A larger total edge must not invent a moneyline recommendation. This legacy panel does not replace canonical released decisions or prove that a wager was placed.
- Streaks share one two-season regular-season history load (three queries including predictions and teams). Preseason/playoffs do not silently enter the regular-season streak. Missing lines, pushes and ties interrupt the applicable streak.

## Trend evidence

- Rest intervals run previous date to current date; historical kickoff classification uses the game's date for daylight-saving time. NFL primetime requires a recognized network and evening kickoff; `Prime Video` is normalized.
- NFL opponent strength reads `home_elo`/`away_elo`. NFL offensive plays include passing attempts, rushing attempts and sacks allowed; missing components suppress the YPP calculation.
- Missing turnover and conversion values are not zero. Rates use only games with the required recorded fields. NFL period-based trends require all four reported regulation quarters on both teams; sparse periods are not renumbered or fabricated.
- Observed ratios take precedence over embedded thresholds when ranking text: `40%+ in 8 of 10 games` has an 80% occurrence rate.
- Scoring averages are retained before threshold truncation. Equal-count nested scoring thresholds are consolidated. Conditional first-quarter/result records survive the quarter-category deduplication.

## Signal W-L and profit reporting

Default outcome samples use the latest graded pregame-safe observation per game and signal. Repeated model runs do not create additional independent wins/losses. This is a signal-context report, not a substitute for the canonical release ledger. All exact decision settlements, including those from earlier runs, remain included; real and tracking-only profit fields remain separate.

```sh
php artisan nfl:report-signal-grades --season=2026 --sample-unit=game --json
php artisan nfl:report-signal-grades --season=2026 --sample-unit=observation --json
```

The second form is explicitly run-level diagnostics. Do not describe its sample count as independent games. `unique_game_count` is available in both modes. A supporting index is added by `2026_09_18_030000_add_nfl_signal_game_sample_index`.

## Verification and release

Regression coverage: `NflTrendSignalRegressionTest`, `NflSignalGradingFoundationTest`, `NflProSignalLayerTest`, the V2 contracts, and shared trend tests. Two old CLV assertions encoded the reversed sign and were corrected; separate symmetric home/away regressions protect the convention.

Expanded cross-sport verification also exposed a pre-existing Carbon subtype mismatch in the shared historical-odds importer. Its snapshot timestamp is now normalized to Laravel's Carbon type without changing the requested historical offset. The NBA fixtures now expect UTC kickoff minus 24 hours, consistent with their own 17:10Z event payload, rather than applying an extra Chicago offset.

The CFB historical team-total venue regression now explicitly starts with a non-neutral target game; previously its factory randomly started neutral, making the venue comparison intermittently fail.

These edits do not regenerate historical predictions, rewrite settlements, or alter previously recorded observations. Deployment must include the migration and frontend build. After deployment, verify the current slate, compare game/observation sample modes, inspect query timings, and confirm the new panel labels. Do not report production fixed before those checks.

Local verification: 1,151 backend tests (NFL, CFB, MLB, NBA, WNBA, V2 contracts and trend units), 17 frontend tests, TypeScript, targeted ESLint/Pint and the production asset build passed. The streak regression asserts three queries with both ATS and total results present; production latency still requires post-deployment measurement.

Remaining hardening: independently validate static historical signal-combination claims, and audit every retrospective market/CLV input against timestamped pregame/closing snapshots. The calculation corrections alone do not establish out-of-sample profitability or complete historical market provenance.
