# NBA manual pilot activation — November 20, 2026

NBA research and shared AI review are active as a **manual pilot**. Automatic analysis scheduling and publisher dispatch remain off. The user explicitly selected: “Keep automation off until source refresh is automated.” The next implementation is documented in the [source refresh plan](nba-source-refresh-plan.md).

Production runs `39807a44a55187fa3d7b2f15a54f3d876a2a0742`, deployment `depl-a346a535-1f95-4b08-a1f1-baee8fdb6eb3`. This is the reviewed foundation plus a narrow review-deadline correction. All 105 release file hashes match production; no migrations are pending.

## Completed sequence and results

1. Revalidated game `1705`, event `13363`, PHI home team `23`, MIL away team `17`, season `2027`, type `2`, scheduled November 21 at noon Central. Identity came through an authorized read-only production database transaction because approved application API authentication was unavailable.
2. Refreshed the packet from the [official 11:45 p.m. Eastern report](https://ak-static.cms.nba.com/referee/injury/Injury-Report_2026-11-20_11_45PM.pdf). Original bytes, exact UTF-8 extraction, hashes and rendered pages 6–7 were reviewed. Both teams' neutral availability excerpts matched; lineup, rotation and rest/travel remain unavailable.
3. Enabled only `SPORTS_RESEARCH_ENABLED` and `NBA_RESEARCH_ENABLED`; research deployment succeeded at 11:05:40 p.m. Central. Registered sources `1/2`, admitted documents `1/2`, and created report `2579`. Assessment is partial/current and expires **November 21 at 12:45 a.m. Central**. Forecast/approval input hash remained unchanged during admission, and no paid review work was created.
4. Enabled `PREDICTION_REVIEW_ENABLED` and `PREDICTION_REVIEW_NBA_ENABLED`; review deployment succeeded at 11:10:19 p.m. Central. This transferred only NBA legacy review ownership. Automation and shared NFL gates stayed off.
5. Dry-run preparation exposed a six-hour deadline serialization error before any paid call. Fixed application-timezone SQL storage and repair of existing deadlines, preserving attempts/leases. **57 targeted tests / 299 assertions and Pint passed**. The correction deployed at 11:17:11 p.m.; work `1` now has the correct noon-Central cutoff.
6. Ran exactly one paid canary: work `1`, generation `3915`, review revision `1`, canonical prediction `71298` / revision `176`. It completed at 11:19:07 p.m. Central and was current when verified. Decision stayed **pass**, with no selection or new approval. Forecast and approval input hashes before/after the call are identical; one attempt is recorded and leases are released.

Usage: `gpt-4o-mini`, **2,200 input / 152 output tokens**, approximately 3.6 seconds. Measured cost is unavailable; admission retains a conservative **$0.05 reservation**, not a claimed bill. The shared OpenAI circuit remained ready. A temporary reporting-script error occurred after persistence; read-only verification confirmed the saved result and no paid call was repeated.

## Preserved NFL and application state

All **137 original environment values** match their pre-activation hashes. The only added variables are the four NBA/global research/review gates above. Original NFL research, daily AI review, canonical reads/writes and worker definitions retain ownership; both shared NFL flags remain false. Post-fix NFL scoreboard, game-detail and signal-grading jobs succeeded. The original NFL readiness warning is unchanged; no hold or approval was modified.

Health/homepage return 200. Protected NBA/NFL research routes return expected unauthenticated 401 responses. Current read-model checks passed; authenticated browser/admin HTTP was not available and no credentials were minted. No new failed-job records appeared after the corrective deployment. Exactly one NBA work item and one NBA review revision exist; two team sources/documents and one shared assessment were admitted.

The existing NBA publishing pipeline may legitimately publish a newer forecast revision between operating stages. Review lineage always binds the exact selected revision; it does not freeze the underlying prediction pipeline. The unchanged-input checks apply around source admission and the actual paid call, not to every intervening scheduled publication.

## State to preserve

```dotenv
SPORTS_RESEARCH_ENABLED=true
NBA_RESEARCH_ENABLED=true
PREDICTION_REVIEW_ENABLED=true
PREDICTION_REVIEW_NBA_ENABLED=true
# Runtime defaults remain false; these keys need not be added:
GAME_ANALYSIS_SCHEDULE_ENABLED=false
GAME_ANALYSIS_PUBLISHER_ENABLED=false
NFL_SHARED_RESEARCH_ENABLED=false
PREDICTION_REVIEW_NFL_ENABLED=false
```

Existing NFL variables must remain unchanged. Source freshness is limited to the packet's original timestamps. Scheduled acquisition does not exist yet; after expiry, the read path must show expired/outdated evidence rather than relabel it fresh. The [implementation plan](nba-source-refresh-plan.md) defines the required acquisition and deduplication work before automation.

For rollback, stage `PREDICTION_REVIEW_NBA_ENABLED=false` to restore legacy NBA ownership, and `NBA_RESEARCH_ENABLED=false` to disable the research pilot; redeploy the current compatible code. If no other sport uses the new global layers, their global gates can also be false. Retain source documents, assessments, work, spend ledger, revisions and tables. Do not clear shared circuits/queues, reset attempts, change old NFL flags or run migration downs. See the [full rollout/rollback procedure](nba-research-rollout.md).

[Redacted execution evidence](nba-activation-2026-11-20.json) contains Cloud command IDs, hashes, actual counts, usage, cutoff, flags and NFL comparison. This record was written locally after activation and was not pushed as another deployment.
