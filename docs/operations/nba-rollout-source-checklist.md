# NBA source admission checklist

Verification date: November 20, 2026, America/Chicago. This checklist completes the source-contract review for the [NBA rollout](nba-research-rollout.md); it does not activate research or certify a current game packet. No production writes, paid calls, provider refreshes, source registration or ingestion occurred.

## Completed checks

| Check | Result and evidence |
| --- | --- |
| Source identity | [Official injury index](https://www.nba.com/players/injury-report) links PDFs on `ak-static.cms.nba.com`; rechecked November 20. |
| Lineup status | [Lineup page](https://www.nba.com/players/todays-lineups) is projected; no dated player rows available in retrieved HTML. |
| Team discovery | [NBA directory](https://www.nba.com/teams) supplies official team links, not a reviewed article catalog. |
| URL safety | HTTPS, exact approved hostname, no credentials/IP/port; redirects separately allowlisted. Existing command tests reject unapproved overrides before HTTP/storage. |
| Provenance | Fetch and storage use the same explicitly selected URL. Re-ingesting unchanged content does not advance observation/publication times. |
| Grounding | Exact excerpts required in stored body; no provider-generated assertions. Topic filtering occurs when evidence is built. |
| Event identity | Event sport must match source; team-specific source must participate in selected event. |
| Team coverage | Event-only source remains `team_side=game` and cannot satisfy home/away topic coverage. |
| Freshness | NBA observation **and**, when supplied, publication must be within 120 minutes. Assessment expiry uses the earliest source freshness boundary or start time. |
| Source revocation | Disabled source causes existing assessment to read `outdated`; reassessment excludes that source. Read does not rewrite history or fetch replacement material. |
| Prediction independence | Assessments are descriptive, with no numerical adjustment or betting approval. Original NFL compatibility stays in its own adapter. |
| Read safety | Research reads do not fetch, queue, call providers or create reports. |

Owning implementations: [ingestion command](../../app/Console/Commands/Sports/IngestResearchDocumentCommand.php), [document store](../../app/Services/Research/ResearchDocumentStore.php), [stored evidence](../../app/Services/Research/StoredGameEvidence.php), [assessment service](../../app/Services/Research/GameResearchService.php), and [sport policy](../../config/research.php).

Validation completed:

```bash
bash scripts/php85.sh artisan test --compact \
  tests/Feature/Research/GameResearchServiceTest.php \
  tests/Feature/Research/IngestResearchDocumentCommandTest.php \
  tests/Feature/Research/NflResearchCompatibilityTest.php
# 28 tests passed / 151 assertions

bash scripts/php85.sh artisan test --compact \
  tests/Feature/Research/NbaSourceAdmissionContractTest.php
# 5 tests passed / 16 assertions

vendor/bin/pint tests/Feature/Research/NbaSourceAdmissionContractTest.php
# passed after formatting
```

The five new NBA regression cases specifically exercise event-only scope, unrelated team/event rejection, old-publication exclusion despite recent observation, publication-boundary expiry, and disabled-source invalidation. Tests use synthetic local fixtures and block stray HTTP requests. No application behavior was changed by this checklist pass.

## Fresh packet acceptance — still required

The [packet manifest template](examples/nba-research-packet.template.json) is an intentionally incomplete operational sidecar, **not** an ingestible source record. The application does not consume this manifest. Do not replace missing evidence with synthetic claims, redate an older report, or mark all topics available to obtain `ready` status.

Before the approved pilot operator runs the source ingestion commands:

- [ ] Resolve one scheduled future game and both team IDs through the authenticated production API. Capture endpoint/query, response time, season, season type, canonical event ID and kickoff offset. Recheck the dated sample IDs in the rollout guide rather than assuming they remain usable.
- [ ] Select an official dated report URL. Retain the original PDF as received, record its SHA-256, URL and original observation time. A cached search result or HTML index is not a game-specific source packet.
- [ ] Confirm the report header publication time and timezone. Convert it to an explicit ISO8601 offset. Publication must not be after observation, and neither may be future dated.
- [ ] At ingestion and assessment time, ensure both timestamps are within the 120-minute NBA window and before the event starts. A publication close to its expiry boundary provides only a short usable window; schedule the pilot accordingly.
- [ ] Extract text using an identified tool/version and documented options. Retain raw PDF hash, extracted UTF-8 text hash and extraction command in the sidecar. No PDF extraction is built into the generic fetch command.
- [ ] Review the rendered PDF alongside extracted text. Check column alignment, wrapped rows, matchup date, team heading and status meaning. A team/date header or “not yet submitted” entry is not evidence that every player is available.
- [ ] Create separate home/away JSON claim arrays from the reviewed text. Each claim's `text` must be an exact substring, `topic` must be one of the configured NBA topics, and `stance` must be `neutral`, `supporting` or `concern`. Default to neutral factual excerpts; do not invent opposing stances to satisfy balance checks.
- [ ] Record the reviewer and review time per team. Confirm every excerpt belongs to the selected team/event. The application verifies source team participation, but does not interpret PDF semantics or prove that an excerpt concerns that team.
- [ ] Keep unknown lineup, rotation and rest/travel coverage explicitly unavailable. An availability-only packet should produce `partial`, not complete coverage. Projected lineups do not prove confirmed starters.
- [ ] Validate existing source catalog policy before registering once per stable team-specific key. Use exact `ak-static.cms.nba.com` allowlist for the PDF workflow. Avoid registering a league-wide record and expecting it to establish both teams' coverage.
- [ ] Stage reviewed body and claims files in the same approved Cloud command/container that performs ingestion; temporary files are not assumed to persist across commands. Use `--file`, not `--fetch`, for extracted PDF text.
- [ ] Supply `--event-id`, original `--observed-at`, and `--published-at` explicitly. The general command permits missing publication and arbitrary string topics; the pilot checklist intentionally rejects those omissions/mismatches before use.
- [ ] After approved ingestion, compare document IDs, URL/content/claims hashes, original timestamps and immutable history with the manifest. Reassessment and admin GET must show correct sides and missing topics without changing predictions/approvals.

Minimal claims shape, showing structure only:

```json
[
  {
    "text": "REPLACE_WITH_EXACT_REVIEWED_SOURCE_EXCERPT",
    "topic": "availability",
    "stance": "neutral"
  }
]
```

Do not ingest the placeholder. For operational packet integrity, hash the exact reviewed bytes rather than a reformatted copy:

```bash
shasum -a 256 report.pdf report.txt home-claims.json away-claims.json
python3 -m json.tool packet.json >/dev/null
python3 -m json.tool home-claims.json >/dev/null
python3 -m json.tool away-claims.json >/dev/null
```

JSON syntax and hashes do not establish semantic accuracy; the reviewer must still check team, report date, exact excerpt, status interpretation and freshness. Store the operational sidecar in approved private release evidence, not a repository file containing a transient production packet.

## Operational gaps and ownership before automation

| Gap | Required completion |
| --- | --- |
| Fresh two-team pilot packet | An operator must acquire and review one shortly before the approved pilot; none is certified by this source audit. |
| Refresh operation | Assign acquisition/review owner, cadence before tipoff, failure handling and fresh-packet delivery. Reconciliation only reassesses already stored material; it does not poll new reports. |
| PDF extraction | Select supported extraction tool and verify rows against rendered PDF. Preserve reproducible extraction metadata. |
| Lineup / rotation / rest-travel | Admit individually verified official articles or clearly sourced internal evidence through a reviewed adapter; do not infer coverage from a directory. These are not blockers to an explicitly partial research-only pilot. |
| Human semantic validation | Exact substring matching cannot detect a quote assigned to the wrong player/team or an outdated report date inside its body. Retain independent review. |
| Source operational health | Source catalog `checked_at`/`succeeded_at` are not automatically updated by ingestion; do not describe them as a polling heartbeat. Monitor actual document observation/publication times and assessment applicability. |
| Paid review separation | Source admission/assessment writes do not authorize provider execution. Keep shared NBA review and automation flags disabled until their separate gates pass. |

The source layer is test-verified for a manually reviewed partial NBA pilot. It is **not yet an unattended current-source service**. A fresh packet and named source-refresh operation remain prerequisites for the corresponding rollout stages.
