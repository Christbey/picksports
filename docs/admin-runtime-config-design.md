# Admin Runtime Config Design

This document proposes the backend design for a DB-backed runtime config layer that can support a true admin panel, live-safe model tuning, and prediction previews without duplicating existing logic.

It is intentionally grounded in the current PickSports codebase.

If this document and the code ever disagree, the code is the source of truth.

## Purpose

Use this document before implementing:
- admin-editable prediction tuning
- model configuration SQL tables
- prediction preview tooling
- experiment and backtest UI workflows
- draft vs active model configuration rollout

This document exists to answer:
- what patterns already exist that we should reuse
- where the current code is already close to the target design
- what new tables and services should exist
- what should not be rebuilt or duplicated

## Short Answer

Yes, PickSports should use a SQL-backed config layer for admin-managed model tuning.

But it should not be built as a parallel config system.

The DRY path is:
- keep `config/*.php` as code-defined defaults
- add DB-backed override/version/draft records
- resolve runtime values through shared services
- plug those services into existing prediction generators, previews, backtests, and admin pages

## Existing Reuse Points

Several parts of the codebase already model the exact shape we need.

### 1. File config plus DB override already exists

Current pattern:
- [app/Services/Settings/FoundingUsersSettingsService.php](/Users/bey/Herd/github/picksports/app/Services/Settings/FoundingUsersSettingsService.php)
- [app/Models/ApplicationSetting.php](/Users/bey/Herd/github/picksports/app/Models/ApplicationSetting.php)

Important behavior:
- file config is the default
- `application_settings` provides runtime overrides
- a service wraps read/write behavior instead of controllers using raw settings directly

This is the simplest existing pattern to generalize.

### 2. Tuning storage already exists for one domain

Current pattern:
- [app/Services/TournamentForecast/CbbTournamentForecastTuningStore.php](/Users/bey/Herd/github/picksports/app/Services/TournamentForecast/CbbTournamentForecastTuningStore.php)
- [app/Actions/CBB/GenerateTournamentForecast.php](/Users/bey/Herd/github/picksports/app/Actions/CBB/GenerateTournamentForecast.php)

Important behavior:
- tuned params are persisted separately from static config
- runtime config is resolved by merging:
  - base config
  - tuned params
  - optional per-run overrides

This is already the correct conceptual model for admin tuning.

### 3. Prediction preview hooks already exist

Current pattern:
- [app/Actions/CBB/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/CBB/GeneratePrediction.php)
- [app/Actions/NBA/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/NBA/GeneratePrediction.php)

Important behavior:
- generators can compute output without persisting a prediction row
- backtest commands already rely on preview methods instead of custom duplicate logic

Examples:
- [app/Console/Commands/CBB/BacktestSpreadsCommand.php](/Users/bey/Herd/github/picksports/app/Console/Commands/CBB/BacktestSpreadsCommand.php)
- [app/Console/Commands/NBA/BacktestSpreadsCommand.php](/Users/bey/Herd/github/picksports/app/Console/Commands/NBA/BacktestSpreadsCommand.php)

This means the admin preview workflow should call shared preview services, not create a second preview implementation.

### 4. Prediction artifacts are already versioned

Current pattern:
- [database/migrations/2026_03_22_170000_add_prediction_versioning_and_ml_prep_tables.php](/Users/bey/Herd/github/picksports/database/migrations/2026_03_22_170000_add_prediction_versioning_and_ml_prep_tables.php)
- [app/Services/Predictions/PredictionFeatureSnapshotRecorder.php](/Users/bey/Herd/github/picksports/app/Services/Predictions/PredictionFeatureSnapshotRecorder.php)
- [app/Services/Predictions/PredictionEvaluationRecorder.php](/Users/bey/Herd/github/picksports/app/Services/Predictions/PredictionEvaluationRecorder.php)

Important behavior:
- predictions already track `model_version`, `feature_version`, and `blend_version`
- snapshots and evaluations are already tied to version identifiers

This gives us a natural place to attach runtime-config versions later.

### 5. Admin pages already have an implementation style

Current pattern:
- [routes/web/admin.php](/Users/bey/Herd/github/picksports/routes/web/admin.php)
- [resources/js/layouts/settings/Layout.vue](/Users/bey/Herd/github/picksports/resources/js/layouts/settings/Layout.vue)
- [app/Http/Controllers/Admin/HealthcheckController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Admin/HealthcheckController.php)
- [app/Http/Controllers/Admin/TierController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Admin/TierController.php)

Important behavior:
- admin routes are centralized
- admin pages are server-driven via Inertia
- controllers are thin and page-focused

This means the admin config UI should fit the existing controller and Inertia page pattern instead of introducing a totally separate panel framework on day one.

## Current Problems

The current codebase is close, but not yet ready for live-safe model tuning through the UI.

### Problem 1. Most tuning still lives in static `config()` lookups

Prediction code reads directly from:
- `config("{sport}.prediction.*")`
- `config("{sport}.predictions.*")`
- sport-specific nested config arrays

Examples:
- [app/Actions/Sports/AbstractPredictionGenerator.php](/Users/bey/Herd/github/picksports/app/Actions/Sports/AbstractPredictionGenerator.php)
- [app/Actions/Sports/AbstractCollegeBasketballPredictionGenerator.php](/Users/bey/Herd/github/picksports/app/Actions/Sports/AbstractCollegeBasketballPredictionGenerator.php)
- [app/Actions/NBA/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/NBA/GeneratePrediction.php)
- [app/Actions/MLB/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/GeneratePrediction.php)

This is fine for code-defined defaults, but it makes:
- draft editing hard
- preview overrides awkward
- version promotion unclear
- rollback difficult

### Problem 2. Override storage is not generalized

Today:
- `application_settings` is generic but very low-level
- `CbbTournamentForecastTuningStore` is domain-specific

What is missing:
- a shared repository for versioned runtime config
- a shared resolver for merging defaults and overrides
- a registry defining what keys are editable and how they should be validated

### Problem 3. Version strings are not yet tied to actual config records

Prediction rows already store:
- `model_version`
- `feature_version`
- `blend_version`

But these are currently code-driven identifiers, not references to a persisted runtime config version.

That means the app can describe prediction artifacts, but it cannot yet answer:
- which exact admin-edited tuning values produced this prediction
- which config version is active for a given sport
- what changed between active version A and draft version B

## Design Goals

The runtime config layer should:
- preserve `config/*.php` as the default source of truth for safe bootstrapping
- allow DB-backed overrides for a selected set of tuneable keys
- support draft, active, and archived versions
- support one-off preview overrides without persistence
- reuse existing prediction generator and preview logic
- keep admin controllers thin
- keep versioning compatible with snapshot and evaluation tables

The runtime config layer should not:
- replace every `config()` call in the app immediately
- move all app settings into SQL
- create a separate prediction engine
- introduce per-sport bespoke override tables unless a generic versioned shape fails

## Recommended Architecture

### A. Keep static config as the baseline

Continue to define defaults in:
- `config/nba.php`
- `config/cbb.php`
- `config/wcbb.php`
- `config/nfl.php`
- `config/cfb.php`
- `config/mlb.php`
- `config/wnba.php`

Reason:
- defaults remain visible in code review
- app boot still works without DB overrides
- local/dev/test environments stay predictable

### B. Add a shared runtime config layer

Introduce these core backend pieces:

- `RuntimeConfigRepository`
  Loads and persists versioned config records from SQL.

- `RuntimeConfigResolver`
  Merges:
  - file defaults
  - active DB version
  - optional draft or preview overrides

- `PredictionConfigRegistry`
  Declares which keys are tuneable, their type, validation, labels, grouping, and risk level.

- `PredictionConfigService`
  Sport-aware facade used by prediction and preview code.

This is the service-oriented extension of the existing `FoundingUsersSettingsService` pattern.

### C. Use versioned records, not a single mutable settings blob

Recommended minimum tables:

#### `runtime_config_profiles`

Represents a logical config family.

Suggested columns:
- `id`
- `domain` (`prediction`, `forecast`, `betting`, later maybe `alerts`)
- `sport` nullable (`nba`, `cbb`, etc. or null for shared)
- `key` unique logical identifier such as `nba.prediction`
- `name`
- `description`
- `created_by`
- timestamps

#### `runtime_config_versions`

Represents one saved version of a profile.

Suggested columns:
- `id`
- `runtime_config_profile_id`
- `version_name`
- `status` (`draft`, `active`, `archived`)
- `source` (`seeded`, `admin`, `command`, `migration`)
- `notes`
- `activated_at` nullable
- `created_by`
- timestamps

#### `runtime_config_values`

Stores the actual override payload for a version.

Suggested columns:
- `id`
- `runtime_config_version_id`
- `path`
- `value_json`
- `value_type`
- timestamps

`path` examples:
- `elo_to_spread_divisor`
- `home_court_points`
- `total_calibration.base_adjustment`
- `win_probability_calibration.enabled`

This shape keeps the schema generic while still allowing per-key diffs and validation.

### D. Add preview/session tables later, not first

Likely useful later:

#### `prediction_preview_sessions`

Stores:
- sport
- game id or input payload
- base config version id
- unsaved overrides
- preview output
- created by

#### `backtest_runs`

Stores:
- sport
- mode (`spread`, `total`, `win_probability`, `forecast`)
- config version id
- input scope
- summary metrics
- artifacts path or payload
- started/completed by

These are valuable, but they are not required to ship the first runtime config layer.

## Resolution Model

Runtime config resolution should work like this:

1. Load file defaults from `config("{sport}.prediction")` or relevant root.
2. Load active DB overrides for that config profile.
3. Merge overrides recursively onto defaults.
4. Optionally merge request-level preview overrides last.

Order:

```text
defaults < active version < draft/preview overrides
```

This matches the existing tournament forecast merge pattern in [app/Actions/CBB/GenerateTournamentForecast.php](/Users/bey/Herd/github/picksports/app/Actions/CBB/GenerateTournamentForecast.php).

## Where To Plug It In First

Do not replace every `config()` call immediately.

Start with the shared prediction access points that already centralize config reads.

### First insertion points

- [app/Actions/Sports/AbstractPredictionGenerator.php](/Users/bey/Herd/github/picksports/app/Actions/Sports/AbstractPredictionGenerator.php)
  Good for shared accessors and fallback behavior.

- [app/Actions/Sports/AbstractCollegeBasketballPredictionGenerator.php](/Users/bey/Herd/github/picksports/app/Actions/Sports/AbstractCollegeBasketballPredictionGenerator.php)
  Good for CBB/WCBB tuneable prediction families.

- [app/Actions/NBA/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/NBA/GeneratePrediction.php)
  Good for NBA-specific prediction weights and total-calibration rollout.

- [app/Actions/MLB/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/GeneratePrediction.php)
  Good for early-season weighting and pitcher adjustments.

### First helper method shape

Instead of doing this everywhere:

```php
$config = config('nba.prediction');
```

Prefer shared helper accessors such as:

```php
$config = $this->predictionConfig();
$value = $this->predictionConfigValue('elo_to_spread_divisor');
```

Where those helpers internally call the runtime resolver.

That lets us migrate a few access points at a time.

## Registry Design

The registry should define only admin-editable keys.

Suggested metadata per key:
- `domain`
- `sport`
- `path`
- `label`
- `description`
- `group`
- `type`
- `default_from_config`
- `validation`
- `ui_control`
- `risk_level`
- `preview_supported`

Example groups:
- spread model
- total model
- win probability
- injury model
- market blend
- forecast simulation
- betting thresholds

Example types:
- boolean
- integer
- float
- enum
- json-object

Reason:
- the UI should not infer forms from arbitrary config arrays
- not every config key should be editable
- registry metadata prevents the admin panel from becoming a raw config editor

## What Should Stay DRY

These behaviors should be reused, not rebuilt:

### Prediction computation

Reuse:
- existing generator classes
- existing preview methods
- existing metadata assembly

Do not build:
- a separate admin-only prediction calculator

### Backtesting

Reuse:
- existing backtest command behavior
- preview recomputation flow

Do not build:
- a second analytics path that computes different outputs than the command layer

### Evaluation and artifact capture

Reuse:
- snapshot recording
- evaluation recording
- model/feature/blend version fields

Do not build:
- a second artifact schema just for admin experiments unless the existing tables are proven insufficient

### Settings access

Reuse:
- service wrappers over low-level storage

Do not build:
- controllers or Vue pages that query settings tables directly

## What Should Not Be Generalized Yet

Avoid these early mistakes:

- moving every app setting into runtime SQL
- trying to normalize every nested config object into custom relational tables
- forcing all sports to share the same editable surface on day one
- replacing every `config()` usage before the runtime layer proves itself

The right first target is tuneable prediction and forecast parameters only.

## Versioning Recommendation

The current version fields are:
- `model_version`
- `feature_version`
- `blend_version`

For the first rollout, keep those fields, but add a runtime-config-aware naming convention.

Example:
- `model_version = rules-v1`
- `feature_version = core-v1`
- `blend_version = baseline-v1`
- `runtime_config_version_id = 12`

Later, if helpful, expose a derived display string such as:

```text
rules-v1 / core-v1 / baseline-v1 / cfg-12
```

That preserves compatibility with existing snapshots and tests while making config provenance explicit.

## Admin Panel Implications

The eventual admin panel should have at least these sections:

### Model Ops

- active config versions by sport
- draft versions
- promote / archive actions
- config diff viewer

### Prediction Preview

- select sport
- select game
- choose base version
- edit draft overrides
- compare current vs preview output
- inspect model metadata

### Experiments

- run backtests against a selected version
- compare metrics vs active version
- persist results for review

### Forecast Tuning

- start with CBB tournament forecast because the code already has a tuning store and calibration command

## Suggested Rollout Order

### Phase 1. Shared runtime config primitives

Build:
- generic versioned config tables
- repository
- resolver
- registry

No admin UI beyond simple read/write testing tools is required yet.

### Phase 2. Migrate one existing tuning domain

Best candidate:
- CBB tournament forecast

Reason:
- tuning storage already exists
- command-driven calibration already exists
- merge semantics are already understood

### Phase 3. Migrate one prediction domain

Best candidates:
- NBA prediction config
- CBB prediction config

Reason:
- preview methods already exist
- versioned artifacts already exist
- backtests already exist

### Phase 4. Build admin preview UI

Only after the runtime config service can safely supply:
- active values
- draft values
- one-off preview overrides

### Phase 5. Expand sport coverage

After one basketball and one non-basketball domain prove the pattern.

## Open Questions

These questions should be answered before implementation:

- Should one profile map to one root config object such as `nba.prediction`, or should profiles be smaller such as `nba.prediction.total_model` and `nba.prediction.win_probability_calibration`?
- Do we want one active version per profile, or staged active versions by environment?
- Should preview overrides be persisted automatically as draft sessions, or remain ephemeral unless explicitly saved?
- Do we want `runtime_config_values` stored path-by-path, or do we want one JSON payload per version plus a derived diff index?

## Recommended First Implementation Slice

The safest first slice is:

1. Create generic runtime config version tables.
2. Build a shared resolver service.
3. Move `CbbTournamentForecastTuningStore` to use the generic runtime config repository under the hood.
4. Add a small admin page for viewing and editing one profile.
5. Add tests proving:
   - file defaults still apply with no DB rows
   - active DB overrides replace defaults
   - preview overrides replace active values without persistence
   - existing forecast and prediction outputs still match when no overrides are present

## Rule Of Thumb

If a new tuning feature:
- computes values, reuse prediction or forecast actions
- stores values, go through shared runtime config services
- previews values, call shared preview flows
- compares results, reuse snapshot and evaluation artifacts

If a change introduces a second implementation of any of those behaviors, it is probably not DRY enough yet.
