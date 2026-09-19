# NFL game-page mobile layout

Below 768px, the NFL game page uses a fixed bottom selector: Overview, Research, Trends, and Roster. The selector respects the device safe area and has 44px minimum touch targets. Changing sections scrolls to the content start. Bottom spacing keeps the last content reachable above the selector.

The NFL matchup header keeps away team, score/status, and home team in one row at every width. Narrow screens use 40px logos and abbreviations; full team names remain in accessible link labels and return visually at desktop widths. Other sports retain the shared header's default layout. Browser checks cover final, live and scheduled states with team logos.

- Overview leads with the prediction instead of dozens of starter cards. Research status and all eligibility reasons remain visible, even when evidence is collapsed. Empty betting plans are omitted.
- The forecast prioritizes home-team model spread and combined-points total. Win probability is displayed once; Elo ratings and box-score details are collapsed. Model lines are explicitly distinguished from sportsbook lines and approved bets. Missing/nonfinite probabilities remain unavailable, valid zero values are retained, and a zero margin is labeled an even matchup.
- Expanded box-score values wrap within the phone width instead of requiring a 600px horizontal swipe. Trend counts share one compact row; narrow pattern badges can wrap.
- Research keeps supporting evidence, counterarguments, prop context, unresolved questions and forecast history in native, keyboard-accessible disclosures. Citations remain attached to their claims.
- Roster contains availability/injuries and an initially collapsed depth chart.
- Trends preserves all history windows, sample sizes and head-to-head context. Mobile selects one team's evidence table at a time; desktop displays both.
- At 768px and above, all page sections remain available regardless of the last mobile selection. The shared page's optional class props do not change other sports' defaults.

Panels are CSS-hidden, not unmounted. Section changes therefore preserve disclosure/history selections and do not restart live polling or issue another research request. Forecast values and grading calculations are unchanged. Betting-plan and depth-chart-context bindings use the actual prediction section data.

## Accuracy and context follow-up

- Recent games request final games of the same season type, strictly before the matchup's UTC kickoff, with the current game excluded. These filters run on the server before pagination. Missing kickoff/phase prevents an unbounded query. The form record is W–L–T; shutouts count, ties are not losses, and unavailable scores remain ungraded.
- Finished games lead with actual margin/total versus stored model values and absolute error. The winner grade is the recorded API value, not recomputed from a mutable forecast. The view does not claim an immutable pregame snapshot or infer ATS/total bet grades from current odds. Missing model values remain unavailable rather than zero. Live betting analysis and current betting plans are not displayed for final games.
- Final-game research is labeled archived. Plain-language reasons are deduplicated; exact reason codes remain expandable. A candidate is not mislabeled as unavailable or as a placed wager.
- Team selection explicitly controls only the statistics table. Matchup history and patterns are labeled as both-team context. Up to three distinct highlights exclude explicitly conditional in-game observations; the full category list is in an outer disclosure, with conditional observations labeled. This is a presentation filter, not statistical validation of the remaining patterns.
- Box scores retain stored update timestamps and display populated-row coverage; these do not certify final synchronization. Availability shows source-timestamp coverage and the oldest supplied update. Missing timestamps and empty injury lists never imply a fresh report. Final-game roster views explicitly identify current availability, not a preserved kickoff roster.

Backend regression coverage includes phase filtering, cross-season history, an offset-bearing cutoff under a non-UTC app timezone, and filtering before pagination. Frontend coverage includes ties/shutouts, missing forecasts, recorded-versus-inferred grading, decision reason deduplication, conditional highlight exclusion and timestamp preservation.

## Verification

Run the normal frontend regression suite, TypeScript check, targeted ESLint, and production build. Browser checks use the real Vue components and CSS with synthetic fixtures; application chrome and API research responses are isolated from production:

```sh
node --experimental-strip-types --test tests/frontend/*.test.mjs
npm run typecheck
npm run build
node tests/browser/nfl-game-mobile.mjs
```

The browser script requires Playwright. If provided by a bundled runtime, set `PLAYWRIGHT_MODULE_PATH` to its `index.mjs`; `PLAYWRIGHT_CHANNEL=chrome` uses an installed Chrome in an isolated headless session. Set `MOBILE_SCREENSHOT_DIR` to an artifact directory to save screenshots.

Checks cover 320px, 390px and 767px screens; 1280px desktop restoration; section selection; research hold visibility; collapsed claims/starters; team and history selectors; minimum navigation target size; no page-level horizontal overflow; and exactly one research fetch per page load. No paid research is invoked.
