# NFL research pipeline

The pipeline gathers official evidence, researches both sides of a model candidate, captures revised game and player-prop forecasts, and evaluates the preserved variants. It does not place bets. New narrative-derived point adjustments are not calibrated or automatically invented.

## Operation

`NFL_RESEARCH_PIPELINE_ENABLED` defaults to true. The configuration lists the official domains for all 32 teams; only teams with scheduled games in the selected date window are polled. The original five-game audit directly verified ten news feeds. Source health is checked at runtime for every configured team, rather than assuming the common feed path works everywhere.

Scheduled jobs:

- Every 15 minutes: ingest eight unseen or oldest-due teams across the next seven days. The full 32-team slate rotates within an hour while each run stays bounded.
- At minutes 7, 22, 37 and 52: review four unseen or oldest-due games per run across the next seven days. This bounded, fair rotation covers the weekly slate without repeatedly revising the same near-term games. Regenerate research only when material supplied evidence changes or context expires. Partial reports retry after the configured delay unless new documents arrive sooner. A per-game lock prevents overlapping research.
- Hourly at minute 55: inspect at most 250 ungraded revisions whose linked game is final with both scores, in 50-row database batches. Future and in-progress games never consume the batch. Grade attempts are persisted in the database: never-attempted finals run first, then the oldest eligible attempt. A pending prop revision moves to the back of the queue and becomes eligible again after the 55-minute cooldown, so it cannot repeatedly consume the first page or starve newer finals.

The older standalone research schedule is disabled while the new pipeline is enabled. Existing AI daily analysis remains and receives the latest researched revision, explicitly separate from the canonical prediction.

Commands:

```sh
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --ingest-only
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=7 --no-ingest --limit=4
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --no-ingest --no-web
php artisan nfl:research-pipeline --date=2026-09-13 --days-forward=0 --briefs
php artisan nfl:research-pipeline --grade
php artisan nfl:research-evaluation --book=fanduel
```

`--no-web` permits a safe numerical preview but holds eligibility if research is missing, changed or stale. `--briefs` reads stored reports without network requests or prediction writes. Ingestion failures are recorded per source and cause nonzero command exit; one source failure does not terminate the remaining source checks.

Research grading accepts `--grade-limit` and `--grade-batch-size`; production
defaults are controlled by `NFL_RESEARCH_GRADING_MAX_PER_RUN` and
`NFL_RESEARCH_GRADING_BATCH_SIZE`. The hard application ceiling is controlled by
`NFL_RESEARCH_GRADING_HARD_MAX_PER_RUN`. Pending retries are controlled by
`NFL_RESEARCH_GRADING_RETRY_AFTER_MINUTES` (or
`--grade-retry-after-minutes` for an individual run). The selected IDs are capped
before they are loaded in database batches, so a run cannot grow beyond its limit.

## Evidence and reconciliation

`nfl_research_sources` tracks HTTP validators, checks, last success and failures. `nfl_research_documents` stores immutable URL/content-hash versions and separate publication/observation times. Both sides receive up to six documents in the research packet. An article update creates a new version even if the URL and original publication date stay the same.

Feed order is not trusted. Newsroom discovery compensates for missing RSS items, particularly Washington's incomplete feed. Article HTML is reduced to text; active code is never executed. Requests are restricted to configured HTTPS official hosts and redirects are revalidated; XML entities are rejected.

Roster tables are parsed using captions and exact player-name links. IR, PUP and commissioner-exempt players are unavailable even if absent from the week's injury report. A roster observation does not provide an injury onset date and must not invent newly vacated usage. Names that cannot be linked unambiguously hold the recommendation. Active roster membership is not proof of game availability or a full workload.

A narrow grammar recognizes explicit official unavailable-status and reserve-activation headlines. Broader prose remains sourced research for interpretation, not an automatic numeric status change. Contradictory generated claims are marked superseded/uncertain, with the authoritative document reference, and the report remains partial. Source observations and original documents remain available for audit.

Names normalize punctuation, accents and generational suffixes within the current team, while ambiguous matches remain blocked. An official roster's relative canonical player-profile path can supply a second identity key (for example, roster label Zach Carter linking to `zachary-carter`); this does not authorize guessed nickname substitutions or cross-team matching. Older roster documents without profile links receive a full fetch before resuming conditional requests. Official roster ingestion queues the existing ESPN roster refresh when identities are missing, at most once per team per hour. A reserve player omitted from ESPN's team roster still requires corroborated current-team evidence and a narrowly audited identity repair; an unmatched or conflicting identity remains held. Evidence changes—including repaired identity matches—expire cached research without treating an unchanged source recheck as new evidence. Conflict checks distinguish “unavailable” and negative clearance statements from positive availability claims.

Research prompts explicitly request supporting evidence, counterarguments, prop context and unresolved questions. Supplied source text is untrusted evidence, not instructions. Supplied document provenance is distinct from provider web-search citations. Report freshness does not establish factual completeness.

## Model changes and eligibility

QB history is filtered by player identity across teams and by regular-season games, with a separate current-team sample count. Veteran experience is no longer downgraded simply because the retrieved sample is small. QB, trench, selected team-efficiency and NFL prop-history queries exclude preseason; the deliberately separate preseason signal remains a distinct component.

`RecommendationEligibility` produces one spread eligibility result from the general model and specialist tiers. A conflicting pass/watchlist, missing QB history, stale/missing sources, missing market, unresolved research or incomplete two-sided evidence holds the researched recommendation. A raw general `bet` cannot conceal a specialist rejection. This conservative policy is a gate, not a learned probability calibration.

Eligibility now distinguishes `hold` (incomplete material data/research), `pass` (data complete but model criteria reject the pick), and `candidate` (both pass). Responses include separate data and model reasons. Unresolved research identifies game, prop or informational scope and whether it blocks assessment. Exact future snap counts, absent regular-season joint-practice evidence and inability to reproduce our prices on secondary sites do not alone block game research. Material questionable-player availability still can. Legacy or malformed uncertainty remains blocking. Prop-specific blocking uncertainty holds the prop previews separately.

Only the pipeline's preview receives verified availability overlays. It reuses existing configured injury/depth weights and the existing conservative prop usage heuristic. AI prose does not add points, invent routes, or assume a replacement inherits all vacated targets. Prop previews use fresh stored quotes, regular-season history and a game-date cutoff; they do not overwrite the original prop snapshot.

## Revisions, interface and evaluation

`nfl_research_revisions` records the original baseline, revised outputs, source identifiers, market quotes, eligibility, two-sided brief and player-prop snapshots. The first captured baseline remains fixed for later revisions even if the canonical model subsequently changes. Duplicate input hashes do not create duplicate revisions. The material hash includes normalized supporting/opposing claims, unresolved questions and sourced facts; meaningful prose changes create a revision even when their URL and scope are unchanged, while casing, punctuation, whitespace and transient timestamps do not. Pregame capture stops at kickoff.

NFL game pages show research, counterarguments, uncertainties and forecast history. `/api/v1/nfl/games/{game}/research` requires authentication and the existing NFL, spread, win-probability and betting-value permissions, subject to the application's configured tier bypass policy. API output excludes full article bodies. The AI prediction payload includes the latest revision separately from the canonical decision contract.

Evaluation stores paired spread/total errors, Brier scores (ties excluded), hypothetical quote returns and prop outcomes at captured lines. Closing-line value is recorded only when a matching-book, matching-side pregame quote exists within 30 minutes of kickoff. Missing closing quotes remain null. These returns are hypothetical, not actual bets.

The aggregate command selects the latest graded pregame revision per game and one selected bookmaker, avoiding treating repeated revisions or books as independent observations. Its all-model ROI includes held leans and is labeled accordingly. Interpret results as descriptive paired comparisons, not causal proof of a particular injury adjustment. Live revisions are not mixed with pregame revisions. No historical results are backfilled using future news.

## Deployment and verification

### September 16 reliability changes

The default research horizon is seven days. Small batches rotate by normalized UTC attempt timestamps, including failed attempts, so the same first games cannot consume every run. The web research service honors the shared AI provider cooldown from every entry point, including research revisions. Exhausted quota creates a failed generation and stops further provider calls; it never creates a substitute report. An unresolved question with an unverified URL remains explicitly unverified and cannot disappear from eligibility checks.

The web request timeout defaults to 120 seconds (`AI_NFL_GAME_CONTEXT_RESEARCH_TIMEOUT_SECONDS`). September 16 production completions took 54–59 seconds and other requests hit the previous 60-second boundary. Four sequential requests are now bounded to approximately eight minutes plus local processing, within the 15-minute schedule cadence. Network timeouts do not automatically retry a potentially paid request; the failed attempt remains visible and rotates back for a later batch.

Source completeness requires current official roster and injury endpoints for each team, plus at least one current official news-discovery channel (RSS or newsroom). RSS and newsroom are alternatives, so a broken optional feed cannot override the other channel's successful evidence. Every endpoint's freshness and error remain visible in the brief. Ingestion has a configurable `NFL_RESEARCH_INGESTION_MAX_SECONDS` budget (180 seconds by default, plus an in-flight request), skips recently checked sources and resumes with the oldest/unattempted sources. A deferred batch is incomplete work and must be resumed; verify actual team coverage rather than assuming a fixed number of batches finished the slate.

Research quote freshness defaults to 60 minutes (`NFL_RESEARCH_MARKET_FRESHNESS_MINUTES`), aligned with canonical market capture and the four rotating batches after an hourly odds refresh. Freshness still uses the actual quote observation timestamp, never the container's updated timestamp. The older 30-minute setting systematically left later batches held even within the same complete-slate cycle. During longer intervals without an odds refresh, market-stale holds remain correct; news research may proceed without pretending that prices are current.

The additive `current_document_id` source pointer preserves which exact immutable roster/injury document a successful fetch confirmed. It changes even when content reverts to a previously observed version; original document timestamps and article publication dates never change. Current confirmed roster documents remain usable beyond the news lookback window, so an unchanged reserve list does not silently disappear after 14 days. Sources without a pointer request the body before accepting conditional responses. Run the migration before ingestion/research.

The research packet now includes timestamped synced weather, line matchups, rest/travel context and model weather metadata. The prompt distinguishes early-season sample uncertainty, pressure/protection matchups, usage changes and roof announcements. These are sourced analysis inputs; prose remains excluded from numerical forecast adjustments.

Weather must be refreshed only after venue metadata exists. ESPN summary payloads supply the venue under `gameInfo.venue`; NFL details sync now restores it and sync guards preserve known names/locations when a partial endpoint omits them. A missing forecast or a per-game provider error makes the weather command fail after processing the rest of its scope. Existing failed rows remain unchanged. Default runs refresh stale rows; `--force` also refreshes recent rows. HTTP success without numeric kickoff-hour temperature, wind and precipitation is rejected.

[Open-Meteo's hourly forecast contract](https://open-meteo.com/en/docs) distinguishes the forecast hour from retrieval time. Selection uses venue-local time and rejects measurements more than an hour from kickoff. Neutral venues take priority over designated home-team coordinates. Retractable stadiums require an explicit roof state before numerical weather adjustments. [SoFi's official venue description](https://www.sofistadium.com/stadium/private-events/holiday-party) identifies a covered, open-air venue: it is neither assumed climate-controlled nor assigned uncalibrated outdoor wind/rain adjustments. Stale or incomplete weather is excluded from numerical adjustments, and subzero temperatures remain valid cold-weather input.

For recovery, run `php artisan espn:sync-nfl-pregame-metadata --season=2026 --days-forward=8 --limit=32`, refresh weather, then regenerate model inputs before reviewing research. The pregame command fetches one ESPN summary per game and updates venue/matchup fields without fetching plays or box scores. It covers only future scheduled/delayed regular-season or postseason games, reports individual failures, and returns failure when its batch limit leaves work deferred. The existing `espn:sync-nfl-game-details` sweep remains final-only; `--days-forward` does not override that filter. Pregame metadata runs at 05:55, 09:55, 13:55, 17:55 and 21:55 before the corresponding weather refreshes. Verify per-game source coverage, weather freshness, revisions and eligibility reasons; a successful scheduler heartbeat alone does not establish complete coverage.

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

### Hold remediation

The production `nflverse_pbp_plays` table was empty. Imported 48,771 plays from the official [2025 nflverse play-by-play release](https://github.com/nflverse/nflverse-data/releases/tag/pbp), including 48,201 EPA values; all imported plays linked to existing games. All five audited games then applied the configured historical EPA fallback. Model version `career-regular-v2` also excludes postseason from this regular-season EPA profile. Refreshed 775 ESPN player records across the ten teams, repairing missing identities and stale team assignments. Original predictions are preserved; research must be rerun to capture these corrected inputs.
# Research uncertainty policy (September 16, 2026)

The research packet includes fresh same-book paired spread, total, and moneyline
quotes. Stale, future-dated, malformed, unpaired, or mismatched total prices are
not sent as usable prices. Secondary web prices never replace these inputs.

Structured unresolved questions carry a `reason_code` and market `scope`.
`routine_starter_confirmation`, `future_report`, `future_usage`, and
`forecast_variance` describe ordinary pregame assumptions, not hard blockers.
They must not be used for a specific injury, real QB competition, preseason
participation plan, or conflicting model input. `material_availability` and
`source_gap` retain material holds. `model_input_conflict` always holds every
market (including props) until verified and recomputed. A total-only gap holds
the total, not the spread; the existing research eligibility is spread-specific.
The brief exposes `market_holds` for every market, not blanket approval of totals
or moneylines from the spread eligibility flag.

Application code preserves the researcher's requested blocking flag and status
when it changes them. It only promotes classified non-game-blocking research
when both teams have cited facts, supporting and opposing evidence exist, and
no source conflict is recorded. Unknown/legacy questions are never silently
cleared. A candidate-context policy version invalidates old reports for fresh
research; historical reports and revisions remain immutable. `ready` describes
usable research, not betting edge or guaranteed player availability. No
uncalibrated confidence boost or narrative-driven numeric adjustment is added.

Regression coverage: `tests/Feature/NFL/ResearchUncertaintyPolicyTest.php`.
The required enum schema follows OpenAI's
[Structured Outputs guidance](https://developers.openai.com/api/docs/guides/structured-outputs);
the existing model, search budget and API timeout are unchanged.
