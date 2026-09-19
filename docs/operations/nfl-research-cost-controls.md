# NFL research cost controls

## Scope and baseline

These controls apply to `nfl_game_context_research` from the pipeline, legacy command and direct service callers. They do not cap other OpenAI purposes, other applications or the OpenAI account itself. No model downgrade, source-validation relaxation or reduction of the five-search/6,000-output-token caps is included.

The read-only production audit on September 18, 2026 observed 432 completed calls over 48 hours, estimated at $23.213806 (about $0.0537 each). There were also 23 failed calls with unknown cost. A subsequent live snapshot showed 17 distinct games and up to 28 completions for one game. These are application estimates, not a reconciled provider invoice or a single scheduler-run bill.

## Refresh policy

- More than 48 hours before kickoff: 24-hour research freshness.
- Between 6 and 48 hours: 6-hour freshness.
- Within 6 hours: 90-minute freshness, never extending beyond kickoff.
- Existing report timestamps and expirations are never renewed by reuse. Entering a tighter window can make an earlier report due before its stored expiry.
- Material source documents, availability/player-linkage, synced injury details, team/QB/coach/venue/kickoff changes and coarse weather/roof changes invalidate reuse. Document ordering, source-check timestamps and market-only changes do not cause paid research. Material candidate changes (bucketed outputs or decision context) require revalidation; identical and immaterial forecasts reuse the report.
- Partial/insufficient reports and unknown failures with unchanged evidence retry no sooner than six hours (90 minutes near kickoff). Changed evidence can retry after a 15-minute minimum interval. A shared per-game lock prevents overlapping manual and scheduled requests.
- Numerical projections and market revisions continue locally. Candidate hashes are captured before prompt compaction and include `raw_bet_classification`, exactly as the reviewer expects. The reuse check validates both evidence and candidate identity. The attempt fingerprint combines both identities, allowing a changed candidate to revalidate after the minimum interval, subject to the unchanged global/per-game budgets. No-web reviews or deferred revalidation retain `research_candidate_changed`; old arguments are never certified against a new forecast. Expired, changed, partial or deferred research remains on hold, including prop/market holds. Prediction payloads reject changed fingerprinted evidence and cannot fall back past a newer insufficient report to an older ready one.

The scheduler still checks regularly and source ingestion continues. Full, bounded sourced requests are used when due; this is not yet a delta-only synthesis or Batch API implementation. Compact JSON removes prompt whitespace without dropping selected facts. The existing provider-citation contract remains enforced.

## Admission budgets

| Environment variable | Default | Meaning |
| --- | ---: | --- |
| `NFL_RESEARCH_DAILY_BUDGET_USD` | 5.00 | Estimated admitted spend, rolling 24 hours, all NFL research games |
| `NFL_RESEARCH_GAME_DAILY_BUDGET_USD` | 0.75 | Estimated admitted spend per game, rolling 24 hours |
| `NFL_RESEARCH_GAME_DAILY_ATTEMPTS` | 8 | Maximum attempts per game, including failed and running attempts |
| `NFL_RESEARCH_RESERVATION_USD` | 0.15 | Estimated reservation before a request; retained when cost is unknown |
| `NFL_RESEARCH_MINIMUM_INTERVAL_MINUTES` | 15 | Minimum interval even when evidence changes |
| `NFL_RESEARCH_RETRY_UNCHANGED_MINUTES` | 360 | Unchanged partial/failed retry interval, shortened by pregame freshness |
| `NFL_RESEARCH_EARLY_FRESHNESS_MINUTES` | 1440 | Early-week research freshness |
| `AI_NFL_GAME_CONTEXT_RESEARCH_FRESHNESS_MINUTES` | 360 | Standard research freshness |
| `NFL_RESEARCH_PREGAME_FRESHNESS_MINUTES` | 90 | Close-to-kickoff freshness |

A shared cache lock serializes the budget check and creation of the durable `ai_generations` reservation. The production cache must support distributed atomic locks. Completed/failed requests with measured usage use their recorded estimate; running requests, timeouts and other unpriced attempts retain at least the reservation. A missing usage ledger or model without matching configured pricing fails closed. `--force` bypasses reuse, not budgets, locks or retry spacing. Budget deferrals do not invoke the provider.

These are **estimated admission controls**, not a guaranteed OpenAI billing hard cap. A request may exceed its reservation, price configuration may be outdated, and timeout usage may remain unknown. Provider billing must still be reconciled. The guard includes existing usage from the last 24 hours: deploying after an already expensive day can defer all new research until that spending ages out. Do not erase usage or reset reports to bypass this protection.

## Accounting and verification

Run `php artisan nfl:research-costs --hours=24` or add `--json` for machine-readable output. The command is read-only and makes no provider requests. It separates recorded estimates, unknown-cost reservations, request counts and recorded searches by game and model. It supports a lookback of 1–168 hours; budget enforcement always uses rolling 24 hours.

Usage is retained when a returned response is incomplete, invalid JSON or fails downstream normalization. No research report is manufactured from such failures. Network failures remain unknown rather than recorded as free. New attempts record the prior report and refresh trigger. Pipeline deferrals are printed, stored in the revision brief and return a failing command status rather than reporting complete research coverage.

Focused tests use fake HTTP only: unchanged reuse, kickoff windows, material changes, partial retries, per-game/global reservations, failed attempts, unknown models, force safeguards, shared locks, malformed/incomplete response accounting, stale prediction payloads, candidate revalidation holds and the reporting command.

## Deployment

Deployed to Laravel Cloud production on September 18, 2026 in commit `f4231e88d4f4c61603478eb3c7b6db853b498478` (deployment `depl-a2c729eb-8d65-4dc2-a49d-8e60beff77bf`, succeeded). Runtime checks confirmed Redis and all nine cost-control settings, including the $5 rolling daily and $0.75 per-game limits. The read-only cost command succeeded: its post-deploy 24-hour snapshot included 250 attempts, $12.139932 recorded estimates, and $2.10 unknown-cost reservations. That pre-existing usage exceeds the new admission budget; do not bypass the resulting research deferrals.

The cost controls need no new database migration beyond the existing AI usage ledger. The accompanying NFL signal index migration was confirmed applied. Deploy application code and rebuilt config cache together on future releases. Inspect `nfl:research-costs` and scheduler deferral reasons, and measure actual provider spend and coverage over a full cycle before claiming savings. Source checks and stale-data holds must remain active when the budget prevents more paid research.

Official references: [OpenAI pricing](https://developers.openai.com/api/docs/pricing), [token usage and output limits](https://developers.openai.com/api/docs/guides/token-counting), [Batch API](https://developers.openai.com/api/docs/guides/batch). Batch processing is not used for late injury/inactive updates because its documented turnaround can be up to 24 hours.
