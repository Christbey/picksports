# NFL research pipeline

The pipeline gathers official evidence, researches both sides of a model candidate, captures revised game and player-prop forecasts, and evaluates the preserved variants. It does not place bets. New narrative-derived point adjustments are not calibrated or automatically invented.

## Operation

`NFL_RESEARCH_PIPELINE_ENABLED` defaults to true. The configuration lists the official domains for all 32 teams; only teams with scheduled games in the selected date window are polled. The original five-game audit directly verified ten news feeds. Source health is checked at runtime for every configured team, rather than assuming the common feed path works everywhere.

Scheduled jobs:

- Every 15 minutes: ingest the next two days of official RSS, newsroom fallbacks, injury pages and rosters.
- At minutes 7, 22, 37 and 52: review games in the next day, regenerate research when supplied documents change or context expires, and append revised forecasts. Partial reports retry after 45 minutes unless new documents arrive sooner. A per-game lock prevents overlapping research.
- Hourly at minute 55: grade completed-game revisions, including props whose official actual values have since arrived.

The older standalone research schedule is disabled while the new pipeline is enabled. Existing AI daily analysis remains and receives the latest researched revision, explicitly separate from the canonical prediction.

Commands:

```sh
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --ingest-only
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --no-ingest
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --no-ingest --no-web
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --briefs
php artisan nfl:research-pipeline --grade
php artisan nfl:research-evaluation --book=fanduel
```

`--no-web` permits a safe numerical preview but holds eligibility if research is missing, changed or stale. `--briefs` reads stored reports without network requests or prediction writes. Ingestion failures are recorded per source and cause nonzero command exit; one source failure does not terminate the remaining source checks.

## Evidence and reconciliation

`nfl_research_sources` tracks HTTP validators, checks, last success and failures. `nfl_research_documents` stores immutable URL/content-hash versions and separate publication/observation times. Both sides receive up to six documents in the research packet. An article update creates a new version even if the URL and original publication date stay the same.

Feed order is not trusted. Newsroom discovery compensates for missing RSS items, particularly Washington's incomplete feed. Article HTML is reduced to text; active code is never executed. Requests are restricted to configured HTTPS official hosts and redirects are revalidated; XML entities are rejected.

Roster tables are parsed using captions and exact player-name links. IR, PUP and commissioner-exempt players are unavailable even if absent from the week's injury report. A roster observation does not provide an injury onset date and must not invent newly vacated usage. Names that cannot be linked unambiguously hold the recommendation. Active roster membership is not proof of game availability or a full workload.

A narrow grammar recognizes explicit official unavailable-status and reserve-activation headlines. Broader prose remains sourced research for interpretation, not an automatic numeric status change. Contradictory generated claims are marked superseded/uncertain, with the authoritative document reference, and the report remains partial. Source observations and original documents remain available for audit.

Research prompts explicitly request supporting evidence, counterarguments, prop context and unresolved questions. Supplied source text is untrusted evidence, not instructions. Supplied document provenance is distinct from provider web-search citations. Report freshness does not establish factual completeness.

## Model changes and eligibility

QB history is filtered by player identity across teams and by regular-season games, with a separate current-team sample count. Veteran experience is no longer downgraded simply because the retrieved sample is small. QB, trench, selected team-efficiency and NFL prop-history queries exclude preseason; the deliberately separate preseason signal remains a distinct component.

`RecommendationEligibility` produces one spread eligibility result from the general model and specialist tiers. A conflicting pass/watchlist, missing QB history, stale/missing sources, missing market, unresolved research or incomplete two-sided evidence holds the researched recommendation. A raw general `bet` cannot conceal a specialist rejection. This conservative policy is a gate, not a learned probability calibration.

Only the pipeline's preview receives verified availability overlays. It reuses existing configured injury/depth weights and the existing conservative prop usage heuristic. AI prose does not add points, invent routes, or assume a replacement inherits all vacated targets. Prop previews use fresh stored quotes, regular-season history and a game-date cutoff; they do not overwrite the original prop snapshot.

## Revisions, interface and evaluation

`nfl_research_revisions` records the original baseline, revised outputs, source identifiers, market quotes, eligibility, two-sided brief and player-prop snapshots. The first captured baseline remains fixed for later revisions even if the canonical model subsequently changes. Duplicate input hashes do not create duplicate revisions. Pregame capture stops at kickoff.

NFL game pages show research, counterarguments, uncertainties and forecast history. `/api/v1/nfl/games/{game}/research` requires authentication and the existing NFL, spread, win-probability and betting-value permissions, subject to the application's configured tier bypass policy. API output excludes full article bodies. The AI prediction payload includes the latest revision separately from the canonical decision contract.

Evaluation stores paired spread/total errors, Brier scores (ties excluded), hypothetical quote returns and prop outcomes at captured lines. Closing-line value is recorded only when a matching-book, matching-side pregame quote exists within 30 minutes of kickoff. Missing closing quotes remain null. These returns are hypothetical, not actual bets.

The aggregate command selects the latest graded pregame revision per game and one selected bookmaker, avoiding treating repeated revisions or books as independent observations. Its all-model ROI includes held leans and is labeled accordingly. Interpret results as descriptive paired comparisons, not causal proof of a particular injury adjustment. Live revisions are not mixed with pregame revisions. No historical results are backfilled using future news.

## Deployment and verification

Deploy the additive migration before running the pipeline. Run source ingestion, inspect failures and document counts, then capture initial revisions and check the original prediction IDs/values remain unchanged. Verify source coverage, both teams' evidence, exact current-team QB sample, reserve player linkage, API authorization and scheduled command registration.

To stop new pipeline runs, set `NFL_RESEARCH_PIPELINE_ENABLED=false`; preserved documents/revisions remain readable. Model fixes require a code rollback if reverting them. Disabling ingestion alone must never promote stale recommendations.

### Initial production verification — September 12, 2026 (Central)

- Additive tables migrated and all three scheduled jobs registered with the pipeline enabled.
- All 40 endpoints for the ten audited teams passed after excluding newsroom navigation/category pages; 212 document versions were captured at the audit checkpoint.
- The existing market import updated 14 games and stored 980 player props.
- Revised briefs for the five audited games preserve original game forecast values and timestamps. Current-market revisions include prop snapshots; the earlier revision without fresh props remains preserved.
- Daniel Jones has 56 prior regular-season appearances in the available database, including 13 with Indianapolis. Cross-team history is no longer discarded. This is the available database sample, not a claim of complete career coverage.
- Verified reserve evidence includes Eli Stowers, Micah Parsons and Josh Jacobs. Unmatched reserve identities, missing true EPA and unresolved research keep recommendations on hold; source ingestion success does not override those gates.
- Explicit cycle collection reduced the sampled ingestion process from roughly 36–38 MB before collection to 24–26 MB afterward. Sources release HTTP/DOM cycles between polls to support full-slate runs.
- Validation: 227 backend tests / 1,350 assertions, frontend type check and production build passed.
