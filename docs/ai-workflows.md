# AI Workflows

This document explains when to use the repo-specific AI skills that support sports-domain development in PickSports.

These workflows complement the existing Laravel Boost skills in `boost.json`.

## Purpose

Use these skills when a change is risky because it crosses:

- shared multi-sport abstractions
- calculation-heavy backend logic
- scheduler-driven sports pipelines

They are meant to reduce silent regressions in places where backend, frontend, domain semantics, and operations overlap.

## Repo-Specific Skills

### `sports-contract-reviewer`

Use when changing:

- API routes
- shared or sport-specific controllers
- Eloquent resources
- frontend game-page or prediction-page consumers
- prediction payload shape
- team metrics filters
- `season` or `season_type` handling
- shared sports-domain semantics

Start with:

- `docs/codebase-reference.md`
- `routes/api/sports.php`

This skill is the best default when a change might break shared API behavior or frontend expectations even if the edit looks sport-specific.

### `calculation-regression-investigator`

Use when a value looks wrong or drifts unexpectedly, including:

- Elo
- team metrics
- prediction outputs
- betting value
- live updates
- injury adjustments
- recommendation outputs

Start with:

- `docs/calculations-reference.md`
- concrete action or service classes that own the value

This skill is best when the question is "why did this number change?" rather than "what file should I edit?"

### `scheduler-operator`

Use when working on:

- scheduled sports sync pipelines
- season-gated jobs
- queue-backed generation workflows
- alert digests
- heartbeat behavior
- schedule timing or stale-data issues
- scheduler documentation drift

Start with:

- `SCHEDULER_REFERENCE.md`
- `routes/console.php`

This skill is best when the issue may be caused by timing, ordering, queue behavior, or schedule drift instead of core business logic.

## Quick Selection Guide

Use `sports-contract-reviewer` when:

- a backend change may affect API consumers
- a resource or route change could break frontend assumptions
- you are changing shared abstractions or sport-specific overrides

Use `calculation-regression-investigator` when:

- a metric, prediction, or recommendation is wrong
- a refactor changed outputs unexpectedly
- you need root-cause analysis across formulas, filters, and serialized output

Use `scheduler-operator` when:

- generated data is stale or missing
- a job appears documented but not actually scheduled
- a pipeline step may be running in the wrong order
- queue-backed work is not producing expected downstream results

## Typical Pairings

- Contract plus calculations:
  Use `sports-contract-reviewer` first, then `calculation-regression-investigator` when a contract change exposes bad or inconsistent computed output.

- Contract plus scheduler:
  Use `sports-contract-reviewer` for API-facing impact and `scheduler-operator` when freshness or generation timing is part of the bug.

- Calculations plus scheduler:
  Use `calculation-regression-investigator` when the logic may be wrong and `scheduler-operator` when the logic may be fine but inputs or refresh timing are stale.

## Existing Boost Skills Still Matter

These repo-specific skills do not replace the existing Boost skills.

Common combinations:

- `pest-testing` when adding or fixing tests
- `wayfinder-development` when frontend changes touch backend routes
- `inertia-vue-development` for Inertia/Vue UI work
- `tailwindcss-development` for Tailwind-heavy UI changes
- `fortify-development`, `socialite-development`, or `cashier-stripe-development` for auth and billing areas

## Rule Of Thumb

If the risk is mostly about:

- shared API and frontend expectations, use `sports-contract-reviewer`
- wrong numbers, use `calculation-regression-investigator`
- wrong timing or stale generated data, use `scheduler-operator`

## Example Prompts

Use prompts like these directly in Codex or Claude when you want to invoke a specific workflow.

### `sports-contract-reviewer`

```text
Use sports-contract-reviewer and review this change to the NBA prediction resource before we ship it.
```

```text
Use sports-contract-reviewer and check whether this new route in routes/api/sports.php breaks any shared frontend assumptions.
```

```text
Use sports-contract-reviewer and verify this MLB game page backend change still matches the existing composables and resource contract.
```

### `calculation-regression-investigator`

```text
Use calculation-regression-investigator and find out why the NFL betting_value field changed after my refactor.
```

```text
Use calculation-regression-investigator and trace why this WNBA team metric is lower than expected.
```

```text
Use calculation-regression-investigator and compare why this NBA prediction spread changed between the old and new code.
```

### `scheduler-operator`

```text
Use scheduler-operator and figure out why MLB predictions are stale this morning.
```

```text
Use scheduler-operator and audit whether the CBB pipeline order in routes/console.php still makes sense after my changes.
```

```text
Use scheduler-operator and check whether the daily digest job is scheduled correctly and whether queue timing could delay it.
```

### Combined Workflows

```text
Use sports-contract-reviewer and calculation-regression-investigator to review this prediction API change and explain any output drift.
```

```text
Use calculation-regression-investigator and scheduler-operator to find out whether this stale NFL recommendation is a logic bug or a pipeline timing issue.
```

```text
Use sports-contract-reviewer and scheduler-operator to make sure this new scheduled sports endpoint workflow is safe end to end.
```
