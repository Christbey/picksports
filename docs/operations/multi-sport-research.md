# Multi-sport descriptive research operations

## Scope and activation

The shared implementation supports NFL, NBA, MLB, WNBA, CBB, WCBB and CFB with separate capability definitions in `config/research.php`. Shared research is disabled by default through `SPORTS_RESEARCH_ENABLED=false` and an independent flag for each sport. NFL uses `NFL_SHARED_RESEARCH_ENABLED`; other sports use `{SPORT}_RESEARCH_ENABLED`. Existing NFL pipeline, source, web-discovery, model, approval and production configuration switches are unchanged.

Non-NFL research is descriptive stored-evidence processing. It uses exact excerpts from explicitly registered sources and stored current-season completed-game results. It does not call a model/provider, discover websites, buy odds, generate predictions, calculate research-based probabilities, change model weights or approve bets. Lineups, availability and other topics remain missing unless actual grounded source documents cover them. Completed-game results do not fill injury or lineup coverage.

The shared web-discovery capability remains disabled. Sources must be approved by an operator for the specific sport and team; this implementation does not invent official URLs, copy NFL source catalogs to other sports, or assert equivalent source availability across leagues. Source catalogs are database entries created through the explicit command below. Enabling research without a source catalog produces an honest unavailable/partial assessment.

The default new freshness windows are capability defaults, not validated guarantees: NBA/WNBA 120 minutes, MLB 90 minutes, CBB/WCBB/CFB 180 minutes. NFL retains its existing refresh policy in the compatibility adapter. Review and approve operational windows before activating a new sport.

## Schema and migration order

Deploy the additive `2026_11_21_010000_create_shared_research_storage` migration before enabling shared research or running document ingestion. It creates:

- `research_sources`: sport-scoped source keys, stable team references, exact approved HTTPS hosts and independent source enablement.
- `research_documents`: immutable source versions with publication time, original observation time, exact-excerpt claims, content identity, event scope and extraction identity.
- `game_research_assessments`: immutable assessment revisions linking the event and generic stored report. A chained assessment identity preserves recovered states without overwriting historical records.

The generic `sports_game_context_reports` table remains the report store. New descriptive reports use `provider=stored` and no model. Old NFL source, document, research revision and grading records remain in their existing tables. No automatic history rewrite or backfill is performed; the NFL adapter reads through existing revision/report IDs. A missing historical lineage is not reconstructed or labeled canonical.

Read-only entry points tolerate absent shared tables and return `research_storage_unavailable`. Migration execution is an explicitly authorized deployment operation; do not run production migrations just to inspect research.

## Register and ingest sources

`sports:ingest-research-document` is a write operation. It does not run implicitly on navigation, analysis reads, or forecast display. Inspect its `--help` in the intended verified environment before operational use.

Register a disabled catalog entry first, using a real operator-approved URL and host in place of the illustrative placeholders:

```bash
php artisan sports:ingest-research-document nba team-official \
  --register-source --register-only \
  --url=https://approved-host.example/news \
  --allowed-host=approved-host.example --team-id=123
```

Source keys are unique within a sport. Team IDs are resolved in that sport's team table. Registration never overwrites an existing source's host/URL policy. An existing source can be explicitly enabled or disabled using `--enable-source` or `--disable-source` with `--register-only`; this does not change environment-level research switches.

Ingest a local document and a JSON claim file:

```bash
php artisan sports:ingest-research-document nba team-official \
  --enable-source --file=/absolute/path/source.txt \
  --claims=/absolute/path/claims.json \
  --observed-at=2026-11-20T11:45:00-06:00 \
  --published-at=2026-11-20T11:30:00-06:00
```

Each claim has `text`, `topic`, and optional `stance` (`neutral`, `supporting`, or `concern`). Text must appear verbatim in the provided document body; inference is not proof of a source statement. Default stance is neutral. Statements and source bodies remain untrusted external evidence, not instructions to the application or review model. The shared pipeline applies no numerical adjustment from them.

```json
[
  { "text": "The exact excerpt from the document.", "topic": "lineup", "stance": "neutral" }
]
```

Use `--event-id` for event-specific evidence, especially doubleheaders, lineup changes or game-specific starter reports. The event must belong to the source's sport and, for a team source, that team must participate. Event-bound documents are never reused as the other game of a same-team doubleheader. Team-scoped documents have no event ID and may be reused while fresh. Same content/extraction in different event scopes retains separate immutable document identities.

Timestamps must include a timezone. Publication cannot be future-dated or later than the original observation. A repeated unchanged ingestion does not reset the original observation or publication time. A successful source check does not make old article facts newly published. A changed excerpt, source version or event scope changes the evidence identity.

Source fetching is optional and explicit: `--fetch` replaces `--file`. It only requests an enabled, approved HTTPS source, rejects credentials, IP hosts and unapproved hosts/redirects, limits redirect hops and document size, and never performs provider web search. No normal read path invokes it. Source activation and document ingestion are atomic; failed ingestion does not silently enable or register a partial catalog entry.

## Assessment and read interfaces

`App\Services\Research\GameResearchService` exposes:

- `enabled(string $sport): bool`: global and per-sport admission gates.
- `assess(Model $game, string $sport, bool $force = false): array`: explicit pregame write entry point. Force cannot bypass disabled capabilities, kickoff, source provenance or add paid discovery.
- `forGame(Model $game, string $sport): array`: strictly read-only; no HTTP, jobs, provider requests or database mutations.

Both entry points validate sport/game identity. Canonical event start time is authoritative when available. New pregame assessments stop at start; existing assessments become archived. Reads recompute applicability against stored evidence, independently of hash-based report reuse.

Returned dimensions remain separate:

| Field | Meaning |
| --- | --- |
| `status` | `ready`, `partial`, or `unavailable` research content |
| `applicability` | `current`, `outdated`, `expired`, or `archived` |
| `source_coverage` | Per topic and home/away grounded evidence availability |
| `supporting`, `concerns`, `facts` | Exact-excerpt findings with document references and provenance |
| `unresolved` | Explicit research gaps; they do not create a betting hold |
| `season_evidence` | Descriptive stored completed-game samples, margins, and record references |

A ready descriptive report requires both supporting and opposing grounded findings and topic coverage for both teams. Partial research remains useful but does not imply an approved selection. Report expiry is capped by kickoff and the oldest applicable source observation/publication freshness bound. If unchanged evidence has expired, repeated assessment does not purchase another call or falsely renew it. Source disablement, new documents, event changes or changed completed-game evidence invalidate applicability.

The NFL compatibility adapter reads the existing revision/report pair and existing evidence/candidate freshness checks. Explicit shared NFL assessment delegates to `ResearchPipeline::review(research: false)`, retaining its existing numerical calculation and decision rules without initiating paid research. Existing NFL scheduled writers and paid admission remain the owner of their current work.

## Validation and rollback

Feature coverage is in `tests/Feature/Research/GameResearchServiceTest.php` and `tests/Feature/Research/IngestResearchDocumentCommandTest.php`. It includes six non-NFL adapters, grounded/balanced findings, missing coverage, immutable revisions, changed evidence, expiry, original timestamps, event-scoped doubleheaders, hydration-stable hashes, read-only behavior, wrong-sport rejection, URL safety, source registration and local file ingestion. Existing NFL pipeline/cost-control suites verify compatibility separately. Tests use SQLite in memory and faked/blocked HTTP, not paid providers.

For implementation testing, use an uncached local configuration with explicit `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' CACHE_STORE=array QUEUE_CONNECTION=sync` and the repository PHP 8.5 wrapper. Do not clear or alter production configuration to run tests.

Rollback first disables shared admission for the affected sport or the global shared flag; preserve records and immutable report history. An individual source may be disabled independently while an operator checks its data. Existing NFL flags and writers remain unchanged. Do not revive a retired legacy forecast writer, erase attempt/reservation records, rewrite historical research or display stale evidence as current to create an apparent recovery. Subscriber research access and paid discovery remain separate rollout decisions.
