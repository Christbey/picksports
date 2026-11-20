# Engineering conventions

Shared guidance for contributors and coding assistants. Read [AGENTS.md](../AGENTS.md) for sports-analysis rules and [the documentation index](README.md) for setup and operations.

Reviewed against repository configuration on November 17, 2026. Dependency manifests and lockfiles, route definitions, configuration files, and tests are authoritative when this guide disagrees with implementation.

## Runtime and layout

- PHP **8.5.x** is required by `composer.json`. Use `scripts/php85.sh` when the default PHP executable differs; it supports `PHP_BINARY`.
- The backend uses Laravel 12. The primary frontend uses Inertia 2, Vue 3, TypeScript, Tailwind CSS 4, and Vite. CI uses Node.js 24. Check `composer.lock` and `package-lock.json` for installed package versions rather than maintaining a duplicate package inventory here.
- `bootstrap/app.php` owns middleware, exception handling, and route wiring. `bootstrap/providers.php` registers providers. Scheduled work lives in `routes/console.php`.
- `app/Models/{Sport}`, `app/Actions/{Sport}`, and sport-specific services sit alongside shared sports abstractions. Check the shared implementation before adding a sport override.
- `resources/js/` contains the primary Inertia application; `frontend/` is a separate Vue workspace. Use the scripts for the frontend being changed.

See [codebase reference](codebase-reference.md), [model lifecycle](model-lifecycle.md), and [API v2 reference](api-v2-reference.md) before changing domain contracts.

## Changes and implementation

Follow neighboring files for naming, structure, casts, enums, and test style. Reuse existing services and components. Keep changes focused; preserve unrelated working-tree changes. Use existing directories and dependencies unless the task requires a reviewed architectural or dependency change.

For PHP, use explicit parameter and return types, curly braces, descriptive names, and constructor property promotion where appropriate. Document non-obvious behavior and useful array shapes; avoid comments that repeat the code.

Use Eloquent relationships and query scopes for model behavior, eager loading to avoid N+1 queries, Form Requests for request validation, and API Resources for serialization. Keep authorization in the existing middleware, policies, and access services. Use query-builder or SQL operations where the query warrants them rather than forcing complex reporting into model relationships.

Read environment variables through configuration files and use `config()` in application code. Never expose full configuration or environment dumps: they can contain credentials. Keep tokens, private keys, customer data, database dumps, and machine-specific connection files out of source control and documentation.

Use queued jobs for background work following the existing queue conventions. A queue worker, scheduler, sync, research command, or deployment helper may write data or call paid services; verify its target and side effects before running it. Engineering access does not change the read-only sports-analysis rules in `AGENTS.md`.

## Domain and API changes

Trace a changed field or calculation through its producer, persistence, resource, frontend consumer, and grading/evaluation path. Preserve the distinction between mutable projections, native canonical forecasts, and imported historical rows. Do not assume a reader flag also enables its writer.

Check season, season type, completed-game cutoffs, timezone, and freshness semantics for the affected sport. Keep current-season evidence separate from historical assumptions and market inputs. Update the relevant contract and regression coverage when payloads or calculations change.

For schema changes, inspect the actual schema and migration history first. Preserve existing column attributes when altering columns. Test migrations on a local database or approved disposable clone; do not use production to discover whether a migration works. The architecture guard requires expand-first changes after August 12, 2026. Document nullability expansions with `@expand-nullability`. Capacity expansions require `@expand-capacity string(64) -> string(128)` or an equivalent signed integer/decimal precision widening declaration (for example, `decimal(5,3) -> decimal(7,3)` with unchanged scale); the guard validates every change chain. Preserve existing nullability, signedness and defaults, and verify the prior definition in migration history. Retire old constraints in a later `_contract_` migration with `@contract-migration`, `@requires-zero-legacy-usage`, and replacement-schema preflight checks.

## Frontend changes

Use existing Vue components and Tailwind patterns. Inertia pages live under `resources/js/pages`; server routes render them through `Inertia::render()`. Follow existing loading, empty, error, and deferred-prop states.

Wayfinder generates route helpers under `resources/js/actions`, `resources/js/routes`, and `resources/js/wayfinder`; these directories are ignored by Git. Import generated helpers according to neighboring code rather than editing their output. Vite invokes `scripts/php85.sh` to generate them. Missing route helpers may require `php artisan wayfinder:generate --with-form` in the verified local environment.

For missing frontend changes, check the relevant development server or rebuild assets. Use `npm run build` for the primary application and `npm run frontend:build` for the separate workspace.

## Tools and project skills

Inspect repository source first for application-specific behavior. Use `php artisan list`, command `--help`, and `php artisan route:list` in the verified local environment to confirm commands and routes. Use `--no-interaction` for unattended commands that support it. Tinker executes application code; use it only when needed and with the same environment and side-effect care as any script.

Laravel Boost tools can help with version-specific framework documentation, schema inspection, and debugging when available. Check which application and database a tool targets before using it; a local Boost connection is not production evidence. If a tool is unavailable, use the repository and appropriate official version-specific documentation rather than treating the missing tool as a blocker.

Project workflow skills currently live in `.claude/skills/`. They include calculation investigation, sports contract review, scheduler operations, testing, and framework integrations. Read the relevant skill when it helps the task and is accessible to your assistant. Do not assume a skill or tool is installed merely because another assistant's generated instructions name it. Shared conventions and operational knowledge belong in these docs, with assistant entry files linking here.

For the three project-specific workflows, use this selection guide:

| Task | Workflow | Starting evidence |
| --- | --- | --- |
| API payload, shared abstraction, or frontend contract change | `sports-contract-reviewer` | `routes/api-v2.php`, owning query/resource, frontend consumer, contract tests |
| Unexpected metric, prediction, or recommendation | `calculation-regression-investigator` | Owning action/service, exact input rows, filters, serialized output |
| Stale generated data, missing job, or pipeline timing | `scheduler-operator` | `routes/console.php`, queue configuration, `SCHEDULER_REFERENCE.md` |

Contract and calculation reviews can be combined when a payload change alters results; add scheduler review when freshness or upstream ordering is involved. Resolve skill examples against the current source before following them. These are development workflows. Runtime AI agents and their operational commands are documented separately in [agents and operations](agents-and-operations.md).

## Validation

Run focused checks for the behavior changed, then the required checks for the affected area. Add or update meaningful regression tests for behavior changes. Use Pest and existing factories for PHP tests; do not replace covered behavior with ad hoc verification scripts or delete tests to make checks pass.

| Area | Check |
| --- | --- |
| Focused PHP behavior | `php artisan test --compact --filter=RelevantTest` |
| PHP formatting check | `composer run test:lint` |
| Format changed PHP files | `vendor/bin/pint path/to/changed-file.php` |
| Main frontend quality | `npm run quality:check` |
| Main frontend build | `npm run build` |
| Separate frontend | `npm run frontend:typecheck` and `npm run frontend:build` |
| Frontend regression tests | `node --experimental-strip-types --test tests/frontend/*.test.mjs` |
| Full PHP test suite used by CI | `composer run test:ci` |

Use only a verified local test environment. `phpunit.xml` uses SQLite in memory, and feature tests use `RefreshDatabase`; inherited environment overrides or cached configuration must not redirect tests to production. The Composer test scripts clear local configuration before running tests.

See `.github/workflows/tests.yml` and `.github/workflows/lint.yml` for the complete CI checks, including ML tests and generated OpenAPI comparison. These files define the checks; the table above is a task-oriented selection. For documentation-only changes, check links, command names, code fences, and whitespace without inventing application tests or claiming live connection verification.

Format only the files being changed when the working tree contains unrelated edits. Report what was checked and any remaining failures or unverified assumptions.

## Documentation maintenance

Update the owning guide when changing a command, environment key, route contract, or deployment behavior. Link to the production and database runbooks instead of duplicating access instructions. Use repository-relative links, dated verification claims, and explicit historical labels for old plans and incident reports.

`composer update` runs `artisan boost:update` through the current Composer hook. Review any generated assistant-file changes so framework boilerplate does not overwrite or become a second source of shared project policy.
