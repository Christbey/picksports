# NFL all-history baseline audit

Production data read September 21, 2026, at 2026-09-21T17:02:14-05:00. No production code,
settings, ratings, predictions, odds or grades were modified. No paid research ran.

Subsequent work: [local code fixes and the pending production repair](nfl-baseline-integrity-remediation.md).
The findings below describe the original audited state.

## Conclusion

The Elo-only baseline is approximately a 50% ATS selector across the larger
history, not an established improvement ready for promotion. On 1,343 common
regular-season games from 2021–2025 with both prior-date Elo ratings, the stored
baseline went **653–643–33, 14 no-picks (50.4%)**. The archived full-historical
model on those exact games went **637–663–32, 11 no-picks (49.0%)**. These are
retrospective reconstructions, not original published wagers or ROI. There is no
statistical claim that their difference generalizes.

## Scope and provenance

- Inspected **4,661 completed regular/postseason games, 2009–2026**: 4,462 regular
  season and 199 playoff games. Preseason is intentionally excluded from this
  regular-season model comparison. Current 2026 coverage is 31 completed games.
- Historical archived lines cover all 4,630 completed regular/postseason games
  from 2009–2025. Current 2026 uses original observed frozen forecast lines, not
  archive closing lines. Those policies are not mixed in the principal totals.
- Stored Elo history starts in 2021 and 2021–2025 rows were created in May 2026.
  Strictly prior-date ratings are used; the first 16 games of 2021 are excluded
  from the stored-Elo common sample because both prior ratings were absent.
- Archived full-historical prediction snapshots cover 2,383 regular-season games
  from 2017–2025, generated in July 2026. All **1,024** 2017–2020 snapshots have
  both Elo values set to 1500. Those years are not evidence of a trained Elo
  baseline. Sixteen 2021 snapshots also contain the default pair.
- The observed-pregame validator examined all 4,462 regular-season games, but
  accepted only 31 current-season games. The other 4,431 lack required canonical
  kickoff/result provenance. We did not relax that contract or relabel archives
  as observations.
- The archive full-model profile differs from the live 2026 model and contains
  inputs not independently revalidated here. Its results are descriptive only.

## Critical findings before calibration

1. **Archived sportsbook spread signs are reversed.** In 4,625 nonzero nflverse
   snapshots, the stored sportsbook home handicap disagrees with the normalized
   source field. The remaining five archive lines are zero. The importer places
   source `spread_line` on the home outcome unchanged. nflverse defines this
   field as a predicted home margin: positive means home favored. The handicap
   must therefore be `-spread_line`. This audit negates the original source field
   in memory only; production payloads remain unfixed. No conflicting source
   spreads were found within this archive selection.
2. **The calibration command can look at the game's own postgame rating.**
   `CalibrateSpreadCommand::getEloAtDate` uses `date <= gameDate`; Elo history
   stores postgame ratings with that game's date. The forecast action instead
   uses strictly earlier dates. Thus the existing calibration utility must not
   be treated as leakage-safe validation. It also lacks the live ±15-point cap.
3. **Tied games are treated as away wins by the shared Elo updater.**
   `AbstractEloCalculator::execute` uses home score greater than away score ? 1 : 0,
   then sets away result to the complement. A tie needs 0.5 for each side. The
   simulation uses 0.5 and is explicitly not an exact replay of that bug.
4. **Missing history masquerades as a usable baseline.** Default 1500/1500
   ratings produce home-field-only margins. They must remain separately labeled,
   not included as evidence of trained baseline performance.
5. **Archive observation times are synthetic.** The importer sets capture time
   to kickoff minus five minutes, while the row is created years later. It also
   parses source Eastern kickoff times without an explicit Eastern timezone.
   This audit uses calendar-date ordering and never treats those synthetic times
   as proof that a quote was observed before kickoff.

Sources: [nflverse schedule dictionary](https://raw.githubusercontent.com/nflverse/nflreadr/main/data-raw/dictionary_schedules.csv),
[importer](../../app/Console/Commands/NFL/ImportNflverseSchedulesCommand.php),
[calibration command](../../app/Console/Commands/NFL/CalibrateSpreadCommand.php),
[Elo updater](../../app/Actions/Sports/AbstractEloCalculator.php).

## Stored-Elo baseline: season by season

Source-normalized nflverse archived closing lines; no additional edge threshold.
Margins rounded to one decimal before choosing a side. Win percentages exclude
pushes and no-picks. This table does not include default-only 2017–2020 snapshots.

| Season | Games | Baseline W–L–P | No-pick | ATS win rate | Margin MAE |
| --- | ---: | --- | ---: | ---: | ---: |
| 2021 (16 missing-prior games excluded) | 256 | 113–138–4 | 1 | 45.0% | 12.02 |
| 2022 | 271 | 139–119–10 | 3 | 53.9% | 9.42 |
| 2023 | 272 | 124–132–14 | 2 | 48.4% | 11.33 |
| 2024 | 272 | 148–117–4 | 3 | 55.8% | 10.39 |
| 2025 | 272 | 129–137–1 | 5 | 48.5% | 11.73 |

On the 1,343-game common sample, baseline MAE was **10.96 points**, archived
full-model MAE **10.56**, and the market benchmark **9.72**. The full model can
improve point accuracy while making worse directional spread selections.

## Fresh chronological simulation: all available seasons

This answers what a simple, consistently initialized baseline could have done;
it is **not** the original production model. Start all teams at 1500 in 2009;
freeze all forecasts on a date before updating ratings with that date's scores;
use fixed production Elo settings (K 16, home advantage 25 Elo, early-week K ×1.1,
playoff K ×1.5, logarithmic margin multiplier capped at 2.2), explicit 33%
offseason regression, and correct 0.5/0.5 tie outcomes. Playoffs update the ratings
but are reported separately. Forecast margin = clip(0.09 × Elo difference + 2.25
home points, -15, 15), with zero home points at neutral sites. No injuries, EPA,
context layers, market blending or future scores enter the forecasts.

2009 is an initialization/warm-up season. Excluding it and partial 2026 leaves
**4,175 regular-season games (2010–2025): 2,024–2,010–105, 36 no-picks, 50.2% ATS**.
Baseline MAE was **10.82**, versus **10.08** for the market on those same games.

| Season | Games | Simulated baseline W–L–P | No-pick | ATS win rate | Margin MAE |
| --- | ---: | --- | ---: | ---: | ---: |
| 2009 (warm-up) | 256 | 122–125–8 | 1 | 49.4% | 12.08 |
| 2010 | 256 | 115–133–5 | 3 | 46.4% | 11.54 |
| 2011 | 256 | 125–117–11 | 3 | 51.7% | 11.01 |
| 2012 | 256 | 117–130–5 | 4 | 47.4% | 11.89 |
| 2013 | 256 | 124–124–6 | 2 | 50.0% | 10.46 |
| 2014 | 256 | 129–117–6 | 4 | 52.4% | 11.61 |
| 2015 | 256 | 135–113–8 | 0 | 54.4% | 10.45 |
| 2016 | 256 | 127–123–5 | 1 | 50.8% | 9.88 |
| 2017 | 256 | 116–131–8 | 1 | 47.0% | 11.30 |
| 2018 | 256 | 119–124–9 | 4 | 49.0% | 11.13 |
| 2019 | 256 | 128–118–10 | 0 | 52.0% | 10.81 |
| 2020 | 256 | 122–134–0 | 0 | 47.7% | 10.41 |
| 2021 | 272 | 144–120–3 | 5 | 54.5% | 11.16 |
| 2022 | 271 | 128–133–10 | 0 | 49.0% | 10.00 |
| 2023 | 272 | 122–132–14 | 4 | 48.0% | 10.80 |
| 2024 | 272 | 141–126–4 | 1 | 52.8% | 10.12 |
| 2025 | 272 | 132–135–1 | 4 | 49.4% | 10.63 |
| 2026 (partial, observed lines) | 31 | 11–18–1 | 1 | 37.9% | 12.89 |

The JSON report contains postseason results separately for every season.

## Testing the conversion factor without fitting on the evaluated season

For each season from 2013 onward, select the Elo-to-points slope and additive
home-field points using **only preceding regular seasons from 2010 onward**.
Grid: slope 0.020–0.100 in 0.005 increments; home advantage 0–4 points in 0.5
increments. Objective: minimum mean absolute margin error. Rating updates remain
fixed, so the forecast home-points parameter is separate from the 25-Elo home
advantage used in rating updates. No ATS results are used to select parameters.

The selected slope was **0.05** in every evaluated season, versus current 0.09.
Selected home points were 2.5 for 2013–2019 and 2.0 for 2020–2026. This is a
chronological diagnostic, not a pristine independent holdout: the original Elo
settings have unknown historical tuning provenance, and this is one tested grid.

| Evaluation sample | Baseline W–L–P / no-pick | Fitted W–L–P / no-pick | Baseline MAE | Fitted MAE | Market MAE |
| --- | --- | --- | ---: | ---: | ---: |
| 2013–2025 | 1667–1630–84 / 26 | 1666–1619–83 / 39 | 10.67 | 10.33 | 9.94 |
| 2024–2025 | 273–261–5 / 5 | 256–278–5 / 5 | 10.37 | 10.19 | 9.67 |

The fitted mapping improved average margin error, but its 2024–2025 ATS rate
fell from **51.1% to 47.9%**. Do not promote 0.05 merely because MAE improved.
The market retained lower MAE. Lower point error and better spread W–L are
separate objectives.

## Current observed 2026 forecasts remain separate

On 31 genuine pregame observations, the saved original Elo baseline remains
**10–20–1**, versus full model **11–18–1 with one no-pick**. Their records are
unchanged by archive normalization. The fresh simulation's 2026 values are not
substitutes for those original forecasts and must not overwrite their grades.

## Recommended next changes, not implemented by this audit

1. Repair nflverse spread and timezone import conventions; add real source-sign
   tests, and plan a scoped, reversible repair of archived payloads and affected
   historical grades. Keep original evidence and correction provenance.
2. Fix tied-game Elo outcomes and unify calibration's prior-date cutoff and
   spread caps with forecasting. Make initial seeds and offseason rollover
   explicit and reproducible.
3. Preserve a lean Elo/market benchmark alongside the model. Require stronger
   later-period directional evidence before changing the production conversion
   factor or adding back adjustment layers.

## Reproduction and verification

- [Read-only audit implementation](../../scripts/audits/NflHistoricalBaselineAudit.php)
- [Saved production input export](../../outputs/nfl-history-inputs-2026-09-21.json.gz.b64)
- [Full report](../../outputs/nfl-all-history-baseline-audit-2026-09-21.json)
- Four focused tests / 15 assertions cover grading signs, zero edges, pushes,
  unavailable lines, no future-score influence, same-date freezing, season
  regression, ties, parameter selection and caps.
- Combined audit/validation/integrity/replay checks passed 34 tests / 156
  assertions. Pint and diff checks passed. The saved report exactly reproduced
  from the archived production input export.
- Only the data extraction ran in production memory. After a remote timeout,
  the exporter used lightweight date comparisons and exported inputs; the full
  parameter comparison ran locally. There were no production database writes.
