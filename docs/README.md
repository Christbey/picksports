# Documentation

## Practical guides

| Guide | Use it for |
| --- | --- |
| [Local development](local-development.md) | Installation, local database, development server, and checks |
| [Production access](operations/production-access.md) | Host verification, SSH, API access, and deployment boundaries |
| [Database access](operations/database-access.md) | Local MySQL/SQLite, production tunnel, connection checks, and troubleshooting |
| [Engineering conventions](engineering.md) | Shared implementation rules, independent of assistant vendor |

## Application references

- [Codebase reference](codebase-reference.md): domain layers and data semantics.
- [API v2 operating policies](api/v2/README.md): changelog, versioning/deprecation, sandbox, and service objectives.
- [API v2 reference](api-v2-reference.md), [endpoint contracts](api-v2-endpoint-contracts.md), and [OpenAPI](openapi-v2.json): routes, authentication, and payloads. Route and request implementations resolve disagreements.
- [Model lifecycle](model-lifecycle.md), [calculations](calculations-reference.md), and [team metrics](team-metrics-architecture.md): prediction and metric ownership.
- [Scheduler reference](../SCHEDULER_REFERENCE.md): scheduled work; confirm against `routes/console.php`.
- [Agents and operations](agents-and-operations.md): application AI workflows and operational commands, some of which write data or call paid providers.
- [Production performance](production-performance.md): Forge deployment helper, worker recycling, assets, and observability.
- [NFL analysis checklist](nfl-game-analysis-checklist.md): scope and evidence for full game breakdowns.
- [Operation-specific runbooks](operations/): use the relevant sport or subsystem guide; check commands against source before running them.

## Verify instructions against source

| Subject | Owning source |
| --- | --- |
| Runtime versions and developer commands | `composer.json`, `package.json`, lockfiles, and `.github/workflows/` |
| Environment names and connection behavior | `.env.example` and `config/`; use active infrastructure for actual production values |
| API routes, access, and filters | `routes/api-v2.php`, middleware, and request classes |
| Scheduled commands and ordering | `routes/console.php` and command implementations |
| Release mutations | `scripts/post-deploy.sh` plus the active platform's deployment configuration |

A successful source review confirms what a command is defined to do. It does not confirm that a server is running that release or that a credential still works.

## Historical documentation

Use the current guides above for operations. Older hosting migrations, rollout plans and incident reports are historical references; their presence does not establish current deployment configuration or production access. Dated local analysis and execution artifacts under `outputs/` record their inspected environment and are not general operating instructions.

## Keeping documentation useful

Put setup and access instructions in the practical guides above. Link to them instead of copying connection instructions into assistant files or sport reports. For a runbook, state its scope, prerequisites, commands, expected result, side effects, and recovery steps where applicable. Record verification dates for environment claims and distinguish code inspection from live checks.

When changing a route, command, environment key, or deployment behavior, update its owning guide in the same change. Keep credentials and machine-specific helpers untracked. Remove superseded plans and completed reports once useful guidance has been consolidated. Use Git history for retired tracked documentation rather than maintaining duplicate archive files.

## Review baseline — November 17, 2026

This documentation pass checked repository scripts, dependency manifests, database configuration, API authentication documentation, and test configuration. It added the missing onboarding/access guides and removed the dependency on Claude for shared engineering conventions. It did not verify live production connectivity or audit every sport formula. Superseded Cloud migration notes and other obsolete plans/reports were removed; the production guide states what remains unverified about the active environment.

## Shared research rollout

- [NBA research rollout](operations/nba-research-rollout.md): opt-in source admission, review ownership, activation and rollback.
- [Shared descriptive research](operations/multi-sport-research.md) and [prediction review execution](operations/prediction-review.md).

## NBA structured evidence

- [NBA capture](operations/nba-injury-observation-implementation.md) and [structured research adapter](operations/nba-structured-research-adapter.md): disabled-by-default acquisition ordering, provenance, verification and activation gates.
- [NBA source refresh plan](operations/nba-source-refresh-plan.md): optional PDFs, overnight coverage and review deduplication gates.

- [NBA adapter release candidate](operations/nba-adapter-release-candidate-2026-11-21.md): live nonproduction end-to-end canary, isolated baseline, review evidence and unexecuted activation/rollback sequence.

- [NBA team canary scope](operations/nba-team-canary-scope.md): two-team, expiring capture/research admission with unchanged nonselected NBA and NFL behavior.
