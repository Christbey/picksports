# NFL frozen spread-policy replay

Run `php artisan nfl:replay-frozen-spreads --season=2026 --through-week=2`.
The command emits JSON and performs no database writes, model promotion, paid
research, live API fetches, or historical prediction regeneration. An empty or
fully excluded replay exits unsuccessfully; partial coverage is explicit.

## What this measures

The default `--variant=safeguards_v1` is a retrospective counterfactual for the spread safeguards, not a full
rerun of every current model component and not an independent validation set.
It quarantines custom EPA, uses the 25% preseason fallback when that branch becomes
active, applies the eight-game reliability prior to previously active efficiency
layers, and removes legacy spread-bias correction. Other feature switches, signals,
market weights and lines remain frozen. Trust/approval changes do not change a
model spread pick and are not scored here. This report does not evaluate ML,
totals, props, confidence calibration, or wager ROI.

Only the two original `nfl-historical-elo-v2` and
`nfl-historical-elo-v2-career-regular-v2` versions are supported. The explicit
spread bounds are -15/+15; recovered home-field points use 0.09 points/Elo.
Changes to these policies need a new replay version, not a silent config change.

## Evidence and exclusions

- The existing validation dataset selects the latest observed, pregame-safe
  forecast before the canonical kickoff. No mutable game odds fallback is used.
- Unchanged stages use saved signal values in original order, including clamps
  and injury-stage rounding even when the injury adjustment is zero.
- Rounded metadata must reproduce the original published spread to 0.1 points.
  Failure excludes the game; tolerance is not relaxed to make a game pass.
- If removing EPA activates preseason blending, use the latest verified canonical
  input snapshot captured no later than the original forecast and before kickoff.
  Source availability must be no later than capture. Team identities, event,
  regular-season type, current record season and finite ratings must match.
- Recovery uses the last archived available ratings, not a guarantee of the exact
  mutable team-metric values at the original forecast instant. Snapshot ID, capture
  time and age are included. No later information fills missing inputs.
- A zero spread edge is a no-pick, not a loss or a forced home selection. Pushes
  and no-picks are separate. Compare original and revised records on the same
  eligible games. Original-all counts are provided separately.
- Frozen lines were observed before kickoff, but this does not prove provider
  freshness or closing-line quality. Saved signals are rounded, not full-precision
  inputs. The report must not be advertised as an exact full-model rerun.

## September 21, 2026 audit

Read-only production replay found 31 completed games: all 16 from Week 1 and 15
from Week 2. It accepted 29 games and excluded two Week 1 games.

| Matched sample | Original W-L-P | Original no-picks | Revised W-L-P |
| --- | --- | --- | --- |
| Week 1, 14 games | 6-7-1 | 0 | 6-7-1 |
| Week 2, 15 games | 5-9-0 | 1 | 6-9-0 |
| Combined, 29 games | 11-16-1 | 1 | 12-16-1 |

Only Raiders at Chargers changed sides: the original +6.5 home-margin forecast
equaled the Chargers -6.5 market line and produced no pick. The counterfactual
home margin was +4.8, selecting Raiders +6.5. Las Vegas won by 12, so it covered.
None of the other 28 eligible games changed pick side.

Exclusions: Dolphins at Raiders (snapshot 182174) could not reproduce the original
published spread from rounded signals; Broncos at Chiefs (187627) lacked verified
pregame fallback ratings. Both were original losses, so omitting them must not be
presented as model improvement. All 15 Week 2 fallback ratings were recovered
from verified canonical snapshots approximately 1-5 hours before their forecasts.

Conclusion: one additional winning selection from a previous no-pick, not evidence
that the broader accuracy problem is solved. The revised matched record is 42.9%
excluding its push; this small retrospective sample does not justify promotion.

Regression coverage includes production fixtures, missing/invalid signals, ordering
and rounding, fallback activation, future-source and wrong-season rejection,
original-output reproduction, and database read-only behavior. Audit-only code is
local until explicitly deployed.

## Isolated formula shadows (local policy v2)

Use `--variant=rushing_direction_only`, `--variant=injury_precision_only`,
`--variant=history_context_off_only`, or `--variant=batch_v2` with the same
season/week arguments. The first three change only the named legacy component;
`batch_v2` combines safeguards v1, the rushing correction and injury precision,
but retains historical context. These are read-only diagnostics, not promotions.

Rushing reconstruction explicitly assumes run weight 1.35, pressure weight 34
and signal cap 4. Historical-context reconstruction assumes adjustment cap 2.
Old snapshots do not freeze all these parameters. The old signal must reproduce
before changing it, but matching a capped value does not uniquely establish the
original parameters. Missing profiles/subcomponents fail closed. Context removal
subtracts the H2H/division/conference/same-week spread components while retaining
venue, rest and coaching; it does not evaluate totals. Injury precision removes
only stage rounding; it cannot restore precision already lost in saved inputs.

Read-only production audit at September 21, 2026, 16:45 CDT:

| Variant | Matched games | Original W-L-P | Original no-picks | Revised W-L-P | Revised no-picks |
| --- | --- | --- | --- | --- | --- |
| Rushing direction only | 30 | 11-17-1 | 1 | 12-17-1 | 0 |
| Injury precision only | 30 | 11-17-1 | 1 | 11-17-1 | 1 |
| Historical context off only | 30 | 11-17-1 | 1 | 13-16-1 | 0 |
| Batch v2 | 29 | 11-16-1 | 1 | 12-16-1 | 0 |

All variants exclude Dolphins at Raiders for failed original reconstruction.
Batch v2 also excludes Broncos at Chiefs for missing pregame fallback ratings.
Do not compare different sample denominators as if excluded losses disappeared.
Rushing alone changes Raiders at Chargers from no-pick to Raiders +6.5 (home
margin 6.4); batch v2 makes the same selection with margin 4.7. Context removal
also changes Cowboys at Giants from a loss to a win (Giants +3, margin -2.7),
and selects Raiders +6.5 with margin 5.5. Rounding alone changes no selections.
These small retrospective results do not establish predictive improvement.

The saved [comparison report](../../outputs/nfl-formula-shadow-comparison-2026-09-21.json)
retains weekly matched records, exclusions and changed picks. Neither historical
forecasts nor production application code were modified by this audit.
