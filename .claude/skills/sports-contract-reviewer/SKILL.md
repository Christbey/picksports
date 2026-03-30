---
name: sports-contract-reviewer
description: "Activate when changing shared or sport-specific sports data contracts, including API routes, controllers, resources, frontend game-page consumers, prediction payloads, team metrics, season or season_type handling, scheduler-driven sports pipelines, or sports-domain semantics. Use for backend/frontend changes that can break shared multi-sport behavior even when the code change looks local."
license: MIT
metadata:
  author: picksports
---

# Sports Contract Reviewer

Use this skill before making changes to shared sports abstractions or any sport-specific feature that flows through those abstractions.

## Read First

Start with the smallest relevant source of truth:

- `docs/codebase-reference.md` for API contracts, shared abstractions, frontend consumers, and sport-domain caveats
- `docs/calculations-reference.md` for metrics, Elo, prediction, and rating logic
- `SCHEDULER_REFERENCE.md` for scheduled pipelines and operational expectations
- `routes/api/sports.php` and `routes/console.php` when the docs and code need to be reconciled

If a doc and code disagree, trust the code.

## When To Activate

Use this skill when work touches any of these areas:

- `app/Http/Controllers/Api/{Sport}` or `app/Http/Controllers/Api/Sports`
- `app/Http/Resources/{Sport}` or `app/Http/Resources/Sports`
- `routes/api/sports.php` or sport API route wiring
- `resources/js/composables` or game-page/prediction-page data loading
- prediction payload fields, permission-gated fields, or by-game prediction responses
- team metrics filters, season filters, `season_type`, or analytics query semantics
- scheduler-driven ingest, prediction, or alert workflows
- sport model semantics such as league alignment, conferences, divisions, injuries, depth-chart context, or probable starter logic

## Review Workflow

1. Identify the contract surface.
   Determine whether the change affects API shape, resource fields, filtering behavior, calculations, scheduler behavior, or frontend expectations.

2. Identify shared abstractions first.
   Check whether the change passes through shared layers before editing sport-specific code.
   Priority locations:
   - `routes/api/sports.php`
   - `app/Http/Controllers/Api/Sports`
   - `app/Http/Resources/Sports`
   - `app/Actions/Sports`
   - `resources/js/composables/useDetailedGameData.ts`

3. Check sport-specific exceptions.
   Do not assume every sport behaves the same.
   Common examples:
   - `games/{game}/prediction` can return either a single object or a one-item collection
   - prediction resources are permission-gated and may omit fields
   - metrics filters can be shared in principle but applied differently in concrete controllers
   - depth-chart data is supplemental and may not be equally reliable across sports

4. Trace frontend consumers.
   For any backend contract change, find the actual callers in `resources/js/composables` and relevant page components.
   Confirm whether they expect:
   - a single object or a collection
   - nullable or missing fields
   - sport-specific field names
   - season, week, or date filter semantics

5. Check calculations and pipeline assumptions.
   If the change affects predictions, Elo, team metrics, betting value, or live updates, review `docs/calculations-reference.md` and the concrete action/service classes before changing formulas or inputs.
   If the change affects sync or scheduled generation behavior, review `SCHEDULER_REFERENCE.md` and `routes/console.php`.

6. Verify with focused tests.
   Prefer the smallest tests that prove the contract still holds.
   Typical targets:
   - `tests/Feature/Api/Sports`
   - sport-specific feature tests under `tests/Feature/{Sport}`
   - resource tests for payload shape
   - calculation or service tests for metric and prediction changes

## Required Checks

Before finalizing, explicitly verify:

- route shape still matches the intended shared contract
- resource payload shape still matches frontend expectations
- permission-gated or tier-gated fields still degrade safely when omitted
- season and `season_type` filters still match the intended semantics
- sport-specific exceptions are still respected
- scheduler or ingest changes still align with `routes/console.php`

## Common Failure Modes

- Making a local resource change that silently breaks a shared frontend composable
- Assuming all sports return the same prediction fields
- Forgetting that some endpoints can return partial payloads for lower-permission users
- Treating depth charts as authoritative when game-level probable starter data should win
- Changing calculation inputs without checking the documented model assumptions
- Updating scheduler docs without checking `routes/console.php`

## Output Style

When reporting findings or implementation notes, summarize impact in terms of:

- affected contract surface
- affected sports
- affected frontend consumers
- test coverage added or updated

Keep the review concrete and file-based rather than abstract.
