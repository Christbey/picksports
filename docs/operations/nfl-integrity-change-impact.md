# Impact of the NFL integrity fixes

September 21, 2026. This is a read-only before/after diagnostic, not a deployment,
database repair, historical regrading or live-model rerun. Production records
were not changed by this review. The prior all-history report already used
normalized source spreads, so its reported 50.4% / 49.0% results remain valid
within its stated reconstruction limits.

## Spread conventions: fixed forecasts, corrected lines

Compare exactly 1,343 regular-season games from 2021–2025, all with prior-date
stored Elo, archived full-model forecasts and nflverse source spreads. The old
payload's home handicap equals the source home margin; the correct handicap is
its negative. Re-select the hypothetical side and grade using each convention.
No predicted margins are changed. These wrong-sign figures recreate the bug's
effect; they are NOT verified previously published picks or wager results.

| Forecast | Wrong-sign W–L–P | No-pick | Wrong-sign win % | Correct W–L–P | No-pick | Correct win % |
| --- | --- | ---: | ---: | --- | ---: | ---: |
| Stored Elo baseline | 992–330–18 | 3 | 75.0% | 653–643–33 | 14 | 50.4% |
| Archived full model | 1,004–318–18 | 3 | 75.9% | 637–663–32 | 11 | 49.0% |

Baseline: 625 side selections and 610 result labels differ. Full model: 583
selections and 634 result labels differ. These counts include transitions into
or out of no-pick. Neither variant changes forecast margins or margin MAE.
The invalid convention systematically makes historical evaluation look better;
fixing data does not mean the model suddenly became worse.

## Calibration: remove the game's own postgame Elo

A fresh read-only production extraction at 18:03 CDT inspected all 1,359
regular-season games from 2021–2025. The old date <= game-date lookup chose the
game's own postgame rating in **all 1,359**. Sixteen games lacked prior ratings;
exclude those and compare the same remaining 1,343 games in every row below.

| Calculation at unchanged 0.09 points/Elo | Margin MAE |
| --- | ---: |
| Same-day/postgame ratings; old uncapped output | 9.651 |
| Prior-date ratings only; still uncapped | 11.131 |
| Prior-date ratings, forecast caps and rounding | 10.965 |

The old result was inflated by outcome information, not superior forecasting.
The isolated cutoff comparison is the first two rows; the third adds the caps
and rounding fix. All rows exclude postseason, although the old command also
evaluated playoffs. This is a common-sample diagnostic, not a verbatim replay of
its entire old default invocation.

Across the tested 0.010–0.150 grid, the best in-sample factor changes from 0.065
with postgame inputs to 0.045 with prior-date inputs. **Neither is promoted.**
These use stored ratings and fixed 25-Elo home advantage, unlike the earlier
fresh-simulation experiment that separately fitted additive home points. Do not
interchange the two coefficients or call either held-out validation.

## Ties: isolate the formula in two fresh chronological simulations

Both simulations start from 1500 in 2009 and use the same dates, scores, source-
normalized lines, fixed Elo parameters and 33% offseason regression. Only the
tie credit changes: old 0/1 versus corrected 0.5/0.5. This is not a rebuild of
the original production Elo sequence, whose initialization history is different.

There are 13 tied games in the exported history. Across 2010–2025:

| Tie handling | W–L–P | No-pick | ATS win % | Margin MAE |
| --- | --- | ---: | ---: | ---: |
| Incorrect away-win credit | 2,025–2,015–105 | 30 | 50.12% | 10.822 |
| Correct half-win credit | 2,024–2,010–105 | 36 | 50.17% | 10.820 |

The correction changes 1,625 rounded downstream margins, at most 1.4 points, and
41 spread selections in that interval. Aggregate performance barely changes;
this is a correctness repair, not an established predictive edge. In partial
2026 the simulation changes 12–18–1 to 11–18–1 plus one no-pick. These simulated
records do not replace the actual observed baseline's 10–20–1 record.

## Time and provenance

The importer now interprets source kickoff in America/New_York. For example,
20:20 Eastern becomes 19:20 Chicago, not 20:20 Chicago; winter/daylight time are
tested. No historical kickoff timestamps were rewritten, and an exact affected
timestamp count cannot be claimed without original schedule evidence. All 4,630
legacy snapshots remain flagged for source-time review by the repair planner.
Archive capture times remain explicitly synthetic, not proof of live observation.

## Evidence and verification

- [Impact report](../../outputs/nfl-integrity-change-impact-2026-09-21.json)
- [Offline comparison](../../scripts/audits/NflIntegrityChangeImpact.php)
- [Original archive input](../../outputs/nfl-history-inputs-2026-09-21.json.gz.b64)
- [Calibration comparison input](../../outputs/nfl-calibration-impact-inputs-2026-09-21.json.gz.b64)
- 29 focused tests / 107 assertions passed. The original all-history report still
  reproduces unchanged. Formatting and diff checks passed.

Remaining operational work is in the [remediation plan](nfl-baseline-integrity-remediation.md).
No original predictions, settlements, odds snapshots or Elo rows were overwritten.
