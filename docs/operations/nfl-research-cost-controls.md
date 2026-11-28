# NFL research admission and cost controls

These controls apply to the `nfl_game_context_research` purpose, not every AI feature or the provider account. Inspect the effective configuration and approved target before an explicitly authorized paid operation. Ordinary game analysis does not authorize a refresh or a forced request.

The source default `NFL_RESEARCH_WEB_SEARCH_ENABLED=false` uses ingested supplied documents. Both teams need selected nonempty source documents without stale/missing source holds. A force request does not bypass that evidence check. An incomplete packet remains held rather than manufacturing a report or availability evidence.

`ResearchSpendGuard` reserves estimated spend while holding the shared budget lock and creates the durable usage reservation before the request. Missing usage tables or unmatched pricing fail closed. Unknown-cost attempts retain at least their reservation; completed attempts use their recorded estimate. Never erase usage or reset report timestamps to regain admission. These are estimated admission controls, not an invoice or guaranteed provider hard cap.

[The source configuration](../../config/nfl_research.php) defaults to a rolling $5 purpose budget, $0.75 per game and $0.15 reservation. General attempt limits are eight, with up to four additional reserved attempts during the configured 12-hour pregame window. Supplied-source mode tightens those limits to two plus at most one reserved attempt. Outside that window its retry spacing is at least 360 minutes; additional freshness, unchanged-evidence and other admission rules also apply. Source defaults are not a claim about current production overrides.

At this source version, an explicit force request bypasses reuse and retry/provider cooldown checks. It still requires the source-evidence, ledger/pricing, lock, monetary-budget and attempt-limit admissions. Force is not permission to spend, acquire providers or remove eligibility holds. Freshness and reuse preserve the original evidence/report clocks; numerical forecasts and source acquisition remain separate operations.

For an authorized read-only usage inspection, `php artisan nfl:research-costs --hours=24 --json` queries the existing ledger without calling an AI provider. Supported lookback is 1–168 hours; admission still uses its rolling 24-hour window. The output separates recorded estimates from unknown-cost reservations and reports by game/model. Reconcile provider billing separately before claiming savings.

This page replaces the dated deployment/cost snapshots and contradictory old freshness table. It does not publish historical deployment identifiers, event-level usage or a current production setting. Use [the research pipeline](nfl-research-pipeline.md) for the owning workflow and source-health boundaries.
