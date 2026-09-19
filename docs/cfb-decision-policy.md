# CFB spread decisions

`cfb-spread-decisions-2` separates directional selections from wager eligibility. It does not change the 1.5.1 score/spread calculator or reset its calibration history.

- Select a real, fresh, paired quote independently of the three-point wagering threshold. Keep the best acceptable handicap, then price, within the existing book-line range.
- Return a preferred side and price for a supported model even below three points. A tie is explicitly `no_edge`.
- `provisional` means a usable directional selection with explicit uncertainty, not calibrated profit or automatic wager approval.
- `blocked` retains the model direction but identifies missing fresh prices, stale forecasts, unsafe snapshots or explicit QB conflicts.
- `unavailable` suppresses a direction when essential model inputs are absent.
- `validated` retains the existing full wagering checks. Probability/EV stays null without validated calibration.

The UI shows the selection first, actual price/book and status, with component-level explanations under “Why this status.” The daily/hourly canonical generation paths use the same service and freeze provisional decisions as `model_lean`, with `is_bet=false`, policy identity and exact entry quote. Existing immutable decisions are not relabeled.

Regression coverage includes Auburn-style +2.5 under a three-point threshold, away-underdog signs, exact ties, explicit injury conflicts, missing inputs, real subthreshold prices, API serialization and immutable provisional tracking. No operator toggle or manual per-game approval is needed to display a provisional selection.
