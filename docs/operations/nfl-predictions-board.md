# NFL predictions board

The default board prioritizes matchups, projected winners, spread leans, and the last research assessment. Season-wide signals load only when More insights is opened and follow the selected season (not the selected date). Internal scores and tiers stay in the detail drawer.

## Presentation contract

- `win_probability` is the probability of a **home** win. The winning side is determined from that probability, never from a market/value `pick_side`. An away winner displays `1 - home_probability`; 50% is an even matchup, and missing probability is unavailable.
- `nfl_board.forecast` contains the latest saved research revision's numeric forecast for an upcoming game, unless the base prediction was updated after that revision. It is labelled Research forecast in details. Final cards retain the base prediction used for grading.
- Fresh paired sportsbook lines are explicitly home-sided in `nfl_board.market.home_spread`. Spread lean is home when `predicted_home_margin + home_spread > 0`, away when negative, and no edge when zero. No fresh quote means Line unavailable; never reuse an ambiguously sided legacy market field.
- Final/live games use recorded research quotes, not mutable live/postgame odds, for pregame comparisons.
- Research checked means the last linked report is ready, unexpired, and its assessment is data-complete. It does **not** mean wager approved or certify that inputs have not changed since the assessment. Missing, expired, newer/unlinked reports, newer base predictions, and data holds remain visible. The drawer shows assessment and forecast timestamps.
- Kickoff is transported as an absolute ISO timestamp and rendered in the browser's local timezone with an explicit timezone label.

`NflPredictionBoardContext` adds two batched queries per NFL slate, selecting the latest revision and report per game. Opening the board never runs inference, source ingestion, or paid research. Existing forecast calculations, decision thresholds, and spend limits are unchanged.

## Verification

Run `php artisan test --compact tests/Feature/NFL/NflPredictionBoardContextTest.php tests/Feature/Api/V2/SportPredictionEndpointContractTest.php tests/Feature/Api/V2/SportPredictionFieldGatingTest.php` and `node --experimental-strip-types --test tests/frontend/*.test.mjs`, then typecheck and build. Check the board, details, search, filters, and More insights at narrow and wide viewport widths.
