# PickSports API V2 Modernization Plan

## Objective

Build a new Laravel-first API layer under `/api/v2` that supports:

- authenticated product access
- tier-based feature gating
- predictable Vue payload contracts
- production payload inspection
- AI-assisted development and validation

Operating details, current migrated contract examples, and the V1 retirement
runbook live in `docs/api-v2-contracts-and-retirement.md`. The full current
route matrix, supported filters, auth rules, and contract-test ownership live
in `docs/api-v2-reference.md`. The generated OpenAPI artifact lives in
`docs/openapi-v2.json`.

The current sport-by-sport architecture, performance, dead-code, AI, and ML
review lives in `docs/api-layer-sport-domain-review.md`.

This is a parallel migration, not a rewrite.

`/api/v1` remains operational throughout the migration and is only retired after:

1. V2 contracts are validated in production.
2. Vue consumers have been migrated.
3. Route usage confirms no remaining dependencies.

## Success Criteria

The project is successful when:

- all Vue-facing sport data is served from `/api/v2`.
- payload contracts are documented and tested.
- sport-specific behavior is driven by configuration and context objects.
- controllers contain no business logic.
- access control is enforced consistently.
- admins can inspect production payloads without exposing debug endpoints.
- V1 sport endpoints can be removed without affecting users.

## Non-Goals

The following are explicitly out of scope:

- rewriting sport models.
- replacing existing prediction engines.
- changing database schemas unless required.
- introducing GraphQL.
- rebuilding Vue pages during Phase 1.
- removing `/api/v1` before migration completion.

Agents should avoid expanding scope beyond the documented phases.

## Target API Structure

```txt
/api/v2/sports
/api/v2/sports/{sport}/games
/api/v2/sports/{sport}/predictions
/api/v2/sports/{sport}/teams
/api/v2/sports/{sport}/players
/api/v2/sports/{sport}/stats
/api/v2/sports/{sport}/markets/player-props
/api/v2/admin/payload-inspector
```

## Architectural Rules

These rules are mandatory.

### Controllers

Controllers may only:

1. Validate requests.
2. Resolve sport context.
3. Call query/action classes.
4. Return API resources.

Controllers must not:

- build queries.
- construct cache keys.
- perform access checks directly.
- perform payload transformations.
- contain sport-specific branching.

### Form Requests

Form Requests own:

- validation.
- normalization.
- pagination limits.
- filter parsing.

Controllers should never manually parse request filters.

### Query Classes

Query classes own:

- database access.
- filtering.
- sorting.
- eager loading.
- tier-aware dataset restrictions.

Query classes return:

- Builder instances.
- Collections.
- Paginators.

Resources should never execute queries.

### Resources

Resources own serialization only.

Resources must not:

- perform authorization.
- execute queries.
- calculate access rules.
- determine feature availability.

Resources may only transform already-approved data.

### Policies And Access

Policies, middleware, and access services own:

- authentication.
- authorization.
- tier enforcement.
- premium field access.

Access logic must never be duplicated in resources or controllers.

## API Principles

- Keep `/api/v1` stable during migration.
- Prefer additive changes over breaking changes.
- All sports APIs are authenticated by default.
- Free access is a policy decision, not a routing decision.
- Shared sport behavior must be centralized.
- Vue payloads are treated as contracts.
- Every contract must be testable.
- All new functionality targets `/api/v2` only.
- Backend and frontend implementation must stay DRY.
- The API and frontend must be AI-friendly: predictable file locations, explicit contracts, small single-purpose classes/composables, documented payload examples, and tests that agents can use to verify behavior.
- Refresh `docs/openapi-v2.json` with `php artisan api:v2-openapi-generate`
  whenever the v2 route surface changes.

## Desired Laravel Structure

```txt
app/
  Http/
    Controllers/
      Api/
        V2/
    Requests/
      Api/
        V2/
    Resources/
      Api/
        V2/
  Services/
    Api/
      V2/
  Policies/
routes/
  api-v2.php
tests/
  Feature/
    Api/
      V2/
```

## DRY By Default

V2 should remove duplicated sport logic instead of recreating it under a new namespace.

Backend DRY rules:

- sport-specific differences belong in `SportContext`, capability config, policies, or small override hooks.
- repeated filters belong in Form Requests or query objects.
- repeated response shape belongs in v2 resources.
- repeated access checks belong in policies, middleware, or access services.
- no controller should duplicate query logic for each sport.

Frontend DRY rules:

- shared API calls should live in one v2 API client.
- shared sport page state should live in Vue composables.
- page components should compose behavior rather than copy fetch/filter/table logic per sport.
- sport-specific display differences should come from config, slots, or small formatter maps.

## 2026-08 API Layer Review Notes

The current API surface has two domains:

- `/api/v1/{sport}` keeps legacy sport-specific controllers/resources alive
  through shared `routes/api/sports.php` registration.
- `/api/v2/sports/{sport}` is the canonical sport-domain API. It resolves
  models/resources through `SportContext`, then delegates filtering to V2 query
  services and serialization to V2 resources.

Sport-by-sport V2 coverage is now contract-tested for:

| Sport | Core domain | Sport-specific V2 behavior |
| --- | --- | --- |
| NFL | teams, players, games, predictions, stats, metrics, injuries, props, futures, signals, depth charts, forecasts | Pro signal data remains calculation metadata; default V2 prediction payloads must not expose `prediction_analysis`. |
| CFB | teams, players, games, predictions, stats, metrics, injuries, leaderboards | Week-zero prediction filtering and CFB signal context are sanitized at the resource boundary. |
| CBB | teams, players, games, predictions, stats, metrics, injuries, props, tournament forecasts | Tournament forecast payloads keep v1-compatible rows while V2 owns auth/metadata. |
| WCBB | teams, games, predictions, stats, metrics, injuries, tournament forecasts | Team/player availability differs from CBB and should continue to flow through capability config. |
| NBA | teams, players, games, predictions, stats, metrics, injuries, props, futures, signals, depth charts, forecasts | Basketball shared logic should stay in V2 query/resource helpers unless NBA needs a presenter override. |
| WNBA | teams, players, games, predictions, stats, metrics, injuries, props | Value signals are public summaries; internal betting-value arrays remain gated. |
| MLB | teams, players, games, predictions, stats, metrics, injuries, props, futures, signals, depth charts, playoff forecasts, daily picks | Pitching, period insights, bullpen ratings, and market-aware recommendations are the richest API extensions and should move toward per-sport presenters. |

Near-term cleanup priorities:

- Keep extracting repeated V2 query mechanics into `App\Services\Api\V2\Concerns\BuildsSportQueries`.
- Split large cross-sport resources, especially `SportPredictionResource`, into
  common payload primitives plus per-sport presenters for MLB, NFL, and CFB.
- Keep raw model inputs, AI narrative fields, and backend calibration internals
  out of default V2 payloads. Public equivalents should use consumer-safe names
  such as `model_level`, `market_edge`, and `market_implied_probability`.
- Continue retiring duplicated v1 sport controllers/resources only after usage
  logs and Vue scans show no callers.

## Sport Context

`SportContext` is the central abstraction used by every V2 endpoint.

It is the single source of truth for:

- sport slug.
- display label.
- namespace.
- model mappings.
- resource mappings.
- supported capabilities.
- status mappings.
- season type mappings.
- access configuration.
- route visibility rules.

No controller should resolve sport configuration directly.

All sport configuration flows through:

```php
SportContextResolver
```

## Query Layer

Each endpoint family receives a dedicated query class:

```txt
SportGameQuery
SportPredictionQuery
SportTeamQuery
SportPlayerQuery
SportStatsQuery
SportMarketQuery
```

### Inputs

```txt
SportContext
Validated filters
Authenticated user
```

### Outputs

```txt
Builder
Collection
Paginator
```

## Vue Architecture

### Shared Composables

```txt
useSportContext()
useSportGames()
useSportPredictions()
useSportTeams()
useSportPlayers()
useSportStats()
useSportMarkets()
usePayloadInspector()
```

### Rules

Composables own:

- API requests.
- loading state.
- error state.
- refresh logic.
- response normalization.
- freshness and warning metadata.

Components own:

- rendering.
- interaction.
- presentation.

No page should duplicate fetch logic already available in a composable.

## AI-Friendly Structure

This project should be easy for AI agents to inspect, modify, and verify without guessing.

AI-friendly rules:

- endpoint contracts should be documented with examples.
- core files should be small and named by responsibility.
- query classes should expose clear inputs and outputs.
- payload inspector profiles should map directly to Vue surfaces.
- tests should describe the contract in plain terms.
- agent workstreams should have non-overlapping ownership.
- docs should include "where to change this" notes when a pattern is introduced.

## Payload Inspector

Admin-only endpoint:

```txt
/api/v2/admin/payload-inspector
```

Purpose:

Provide a production-safe mechanism for validating exactly what Vue receives.

Supported profiles:

- `dashboard`
- `live-scoreboard`
- `sport-predictions`
- `player-props`
- `admin-healthcheck-cards`
- `user-bets`
- `cbb-brackets`
- `settings-admin`
- `alert-preferences`

Supported outputs:

- source endpoint.
- request parameters.
- serialized payload.
- missing required fields.
- null critical fields.
- freshness metadata.
- validation warnings.
- healthcheck findings.
- cache metadata.

The inspector must never expose secrets, tokens, internal credentials, privileged user data, or unnecessary personally identifiable data.

## Versioning Rules

### Allowed

- adding fields.
- adding endpoints.
- adding metadata.

### Not Allowed

- removing fields.
- renaming fields.
- changing response structures.

Breaking changes require:

```txt
/api/v3
```

## Contract Standards

### Collection Response

```json
{
  "data": [],
  "meta": {
    "sport": "mlb",
    "filters": {},
    "pagination": {},
    "tier": {},
    "freshness": {},
    "warnings": []
  }
}
```

### Item Response

```json
{
  "data": {},
  "meta": {
    "sport": "mlb",
    "freshness": {},
    "warnings": []
  }
}
```

Critical Vue fields must be explicitly tested.

The frontend should never depend on undocumented nested fields.

## Testing Requirements

Minimum required coverage:

```txt
Context resolution
Route registration
Request validation
Authorization
Tier enforcement
Prediction leakage
Payload contracts
Admin payload inspector
V1 compatibility
```

Required test locations:

```txt
tests/Feature/Api/V2
tests/Feature/Authorization
tests/Feature/Admin
```

Required commands:

```bash
php artisan test tests/Feature/Api/V2
php artisan test tests/Feature/Authorization
php artisan test tests/Feature/Admin
```

## Agent Ownership

Ownership is exclusive.

An agent may review another area but may not implement overlapping responsibilities.

| Agent | Ownership |
| --- | --- |
| API Architect | Context system, routes, capabilities, boundaries |
| Contract Agent | Payload schemas, contract tests |
| Access Agent | Authentication, authorization, tier controls |
| Data Integrity Agent | Freshness, validation findings, consistency checks |
| Vue Migration Agent | Frontend migration, composables, feature flags |
| Documentation Agent | Endpoint docs, runbooks, examples |

## Implementation Order

Work must occur in this sequence.

### Phase 1: Foundation

Deliverables:

- `routes/api-v2.php`
- `SportContext`
- `SportContextResolver`
- Form Requests
- `/api/v2/sports`

Success criteria:

- V1 remains untouched.
- every configured sport resolves correctly.
- unsupported sports return clean JSON 404 responses.

### Phase 2: Games And Predictions

Deliverables:

- game endpoints.
- prediction endpoints.
- API resources.
- contract tests.

Success criteria:

- dashboard can run on V2 behind a feature flag.
- prediction access is properly enforced.
- filtering behaves consistently across sports.
- prediction fields cannot leak through narratives or nested resources.

### Phase 3: Teams, Players, Stats, And Markets

Deliverables:

- teams endpoint.
- players endpoint.
- stats endpoint.
- player props endpoint.

Success criteria:

- unsupported capabilities return clean errors.
- stable pagination and metadata contracts exist.
- capability handling is centralized in `SportContext`.

### Phase 4: Payload Inspector

Deliverables:

- inspector endpoint.
- payload profiles.
- freshness validation.
- missing-field validation.
- healthcheck finding integration where possible.

Success criteria:

- production payloads can be inspected safely.
- stale data can be diagnosed quickly.
- inspector output is safe to share internally.

### Phase 5: Vue Migration

Deliverables:

- V2 API client.
- shared Vue 3 composables.
- dashboard migration.
- prediction page migration.
- stats and markets migration.
- V1 fallback during rollout.

Success criteria:

- every migrated page has contract coverage.
- shared composables own API behavior.
- no page relies on undocumented V1 quirks.
- payload inspector can validate every migrated surface.

### Phase 6: V1 Retirement

Deliverables:

- usage logging.
- deprecation notices.
- removal of unused V1 consumers.

Success criteria:

- route usage confirms migration completion.
- V1 sport APIs can be removed safely.

## First Implementation Slice

Build only the following:

1. `routes/api-v2.php`
2. `SportContext`
3. `SportContextResolver`
4. `/api/v2/sports`
5. `/api/v2/sports/{sport}/games`
6. contract tests for game list/show payloads
7. payload inspector shell with the `dashboard` profile

Nothing else should be implemented until these pieces are complete and tested.
