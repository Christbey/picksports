---
name: calculation-regression-investigator
description: "Activate when a sports metric, Elo value, prediction, betting value, live update, injury adjustment, or recommendation looks wrong, drifts unexpectedly, or needs root-cause analysis. Use for regressions in calculation-heavy backend changes, model inputs, season filtering, scheduled generation workflows, or sport-specific formula behavior."
license: MIT
metadata:
  author: picksports
---

# Calculation Regression Investigator

Use this skill when the question is not just "what changed" but "why did this number or recommendation change?"

## Read First

Start with the smallest relevant source:

- `docs/calculations-reference.md` for formulas, shared inputs, and source file locations
- `docs/codebase-reference.md` for API contracts and frontend consumers of calculation output
- `SCHEDULER_REFERENCE.md` for scheduled generation and sync expectations
- concrete implementation files when the docs and code differ

If a doc and code disagree, trust the code.

## When To Activate

Use this skill when work touches or investigates:

- Elo calculation
- team metrics
- prediction generation
- live prediction updates
- betting value
- injury or depth-chart impact on prediction inputs
- recommendation services driven by model output
- season, week, date, or `season_type` filtering that changes calculated results
- scheduler-driven generation pipelines that can change when data is produced

## Investigation Workflow

1. Define the symptom.
   Identify the exact value or output that looks wrong.
   Examples:
   - a prediction spread changed unexpectedly
   - a team metric is missing or lower than expected
   - betting value disappeared after a refactor
   - a recommendation now overweights injury or depth-chart context

2. Find the owning layer.
   Trace the value back to the first class that computes or transforms it.
   Common locations:
   - `app/Actions/Sports`
   - `app/Actions/{Sport}`
   - `app/Services`
   - `app/Http/Resources/{Sport}`

3. Separate calculation changes from presentation changes.
   Confirm whether the regression is:
   - bad source data
   - wrong query/filtering
   - wrong formula or weighting
   - serialization/resource omission
   - permission-gated field removal
   - frontend interpretation issue

4. Check shared inputs before formulas.
   Many regressions come from the data set, not the equation.
   Verify:
   - final-game filtering
   - `season`
   - `season_type`
   - date or week windows
   - eager-loaded relations
   - home/away or neutral-site handling
   - injury or probable-starter overrides

5. Check sport-specific overrides.
   Do not assume the shared formula is the whole story.
   Review the concrete sport class for:
   - custom K-factors
   - playoff or postseason multipliers
   - margin-of-victory adjustments
   - pace or possession coefficient differences
   - opponent-adjustment behavior
   - depth-chart or starter weighting

6. Check pipeline timing when results appear stale.
   If the value is correct in code but wrong in the app, confirm whether the relevant scheduler job has run and whether the job depends on prior ingest or queue work.

7. Verify with the smallest targeted test.
   Prefer updating or adding a focused feature or unit test around the broken behavior instead of broad reruns.

## Required Checks

Before finalizing, explicitly verify:

- the input rows used by the calculation are the intended rows
- the sport-specific implementation still matches the documented model
- the output field is serialized where the consumer expects it
- scheduler timing is not the real reason for apparent drift
- the affected test covers the regression path directly

## Common Failure Modes

- debugging the formula before verifying the filtered game set
- assuming shared abstract logic is used when a concrete sport class overrides it
- blaming the backend when a resource or permission gate removed the field
- ignoring scheduled job ordering when generated values lag behind ingest
- using current Elo or ratings where historical pre-game context was intended
- treating supplemental depth-chart data as the primary source when explicit game context should win

## Useful Search Pattern

When investigating, search in this order:

1. output field name
2. owning resource or service
3. concrete sport action
4. shared abstract action
5. tests covering the same value

## Output Style

When reporting results, summarize:

- observed symptom
- root cause
- whether it was data, formula, serialization, or scheduling
- exact files changed or inspected
- focused tests added or run
