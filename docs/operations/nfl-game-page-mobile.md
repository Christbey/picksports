# NFL game-page mobile layout

Below 768px, the NFL game page uses a fixed bottom selector: Overview, Research, Trends, and Roster. The selector respects the device safe area and has 44px minimum touch targets. Changing sections scrolls to the content start. Bottom spacing keeps the last content reachable above the selector.

- Overview leads with the prediction instead of dozens of starter cards. Research status and all eligibility reasons remain visible, even when evidence is collapsed. Empty betting plans are omitted.
- Research keeps supporting evidence, counterarguments, prop context, unresolved questions and forecast history in native, keyboard-accessible disclosures. Citations remain attached to their claims.
- Roster contains availability/injuries and an initially collapsed depth chart.
- Trends preserves all history windows, sample sizes and head-to-head context. Mobile selects one team's evidence table at a time; desktop displays both.
- At 768px and above, all page sections remain available regardless of the last mobile selection. The shared page's optional class props do not change other sports' defaults.

Panels are CSS-hidden, not unmounted. Section changes therefore preserve disclosure/history selections and do not restart live polling or issue another research request. Forecast values and grading calculations are unchanged. Betting-plan and depth-chart-context bindings use the actual prediction section data.

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
