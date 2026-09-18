# NFL team evidence windows

The game-page trend request adds `profile=team_analysis` to the existing authenticated V2 team-trends endpoint. Legacy requests keep their existing shape. A profile loads completed, scored regular-season games for the target season plus the preceding three seasons, strictly before the UTC kickoff cutoff. Five batched queries load games, teams, statistics and predictions once per team; switching windows performs no network requests. The profile has a separate cache key.

## Display

- Default: last 5 regular-season games, across season boundaries when necessary.
- Last 10: the same eligible history with a ten-game limit.
- This season: the target season only, without inventing prior games when the sample is small.
- Historical: the preceding three regular seasons, excluding the target season.

Each window supplies its W-L-T record, game IDs, dates, seasons, metric-specific game counts and most recent stored source-update timestamp. The pattern list, categories and counts change with the selected window. Head-to-head records remain separate and unchanged.

Metrics include scoring, scoring margin, yards per offensive play (including sacks in play count), turnovers, third-down/red-zone rates and opponent yards allowed. Values are means of valid per-game values, not pooled attempt rates. Missing values are excluded, never replaced by zero. Missing team responses are not compared against the other team's available response.

The evidence table excludes invalid event counts (negative or non-integral attempts, sacks, interceptions, fumbles lost and conversions). Recorded zero counts remain valid; conversion rates need positive attempts and conversions cannot exceed attempts. An empty window shows an explicit no-history message rather than a zero record. When either response supplies evidence windows, the other side must also supply that selected window; legacy samples are not substituted.

## Changes, not invented edges

The change view compares the last 5 games with the preceding 10, excluding overlap. Each metric needs at least 3 recorded games in each sample to emit a numerical delta. It does not assign a betting direction, calibrated probability, or statistical-significance claim. Other windows overlap and must not be summed as independent support.

Only the four displayed windows run trend collectors and signal scoring. The preceding-ten comparison baseline calculates summary metrics directly, avoiding an unused fifth collector/scorer pass. All windows still share the same five-query data load.

Historical model/Elo context is withheld when the legacy prediction was updated after its own game's kickoff, or its timestamp is absent. Scores and game stats reflect the currently stored records, including corrections; this display is not a point-in-time backtest. Mutable season aggregates are not substituted for window-specific EPA, pressure or historical injury data.

Game-specific research, roster/injury information, weather and market analysis remain in their existing game-page sections. This release does **not** claim a fully validated combined team/matchup/market model. That requires separately timestamped inputs and out-of-sample evaluation.

## Verification

`NflTeamEvidenceTest` covers window boundaries, UTC offsets, non-overlapping comparisons, ties, actual stat coverage, postgame prediction rejection, empty samples, five-query loading and cache isolation. Frontend tests cover window-driven pattern changes, preserved head-to-head context and visible unavailable values. The live lifecycle regression verifies the new profile parameter and confirms heavy context is not polled.

Cleanup regressions additionally check four collector passes, valid zero versus invalid counts, per-game (not pooled) conversion rates, preserved endpoint identity/tier fields, mixed legacy/profile responses and explicit empty-history rendering.

The backend and initial evidence-window UI were deployed to production on September 18, 2026 in commit `f4231e88d4f4c61603478eb3c7b6db853b498478`; Laravel Cloud reported deployment success. No production records or historical model outputs are rewritten by this feature. See [mobile game-page behavior](nfl-game-page-mobile.md) for the responsive presentation and browser checks.
