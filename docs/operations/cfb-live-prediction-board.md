# CFB live prediction board

The canonical pregame prediction and markets remain immutable. `/cfb/predictions`
overlays the latest saved `cfb_live_prediction_snapshots` row onto separate
`live_*` API fields. The canonical query eager-loads only the latest snapshot per
game; rendering the board does not run predictions, call providers, or buy research.

- `live`: snapshot is no more than three minutes old and the game is in progress,
  at halftime, or at the end of a period. Live spread retains the existing
  home-margin convention; pregame canonical spread is not modified.
- `updating`: no live snapshot yet.
- `unavailable`: snapshot has no usable projection, with a public reason such as
  missing pregame baseline or incomplete score/clock.
- `stale`: timestamp is old, missing, or in the future; live numbers are withheld.
- `inactive`: game is scheduled or finished; live numbers are withheld.

The visible CFB board refreshes its current page every 30 seconds, including
scheduled games so kickoff does not require a reload. It pauses while hidden,
refreshes on return, and stops requests for a final-only page. Background failures
retain cards with a visible refresh-delay notice. Late responses cannot overwrite
a newer filter request. Timers and visibility listeners are removed on unmount.

This fixes display plumbing, not upstream polling frequency or missing pregame
coverage. It does not label stale forecasts fresh or manufacture missing inputs.

Regression checks:

```sh
vendor/bin/pest tests/Feature/Api/V2/CfbPredictionBoardLiveTest.php tests/Feature/CFB/LiveBettingTest.php
node --experimental-strip-types --test tests/frontend/cfbPredictionBoardLive.test.mjs
npm run typecheck
```
