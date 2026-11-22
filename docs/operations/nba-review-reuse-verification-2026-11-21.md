# NBA review reuse verification — November 21, 2026

Prepared a local candidate on `codex/nba-review-provenance-reuse`, based on deployed release `2dbf544c`. **No deployment, settings changes, source acquisition, OpenAI calls or automation activation occurred in this pass.** The new `NBA_REVIEW_PROVENANCE_REUSE_ENABLED` key defaults false.

## Verified locally

- **233 tests / 1,336 assertions passed on SQLite and on disposable MySQL 8.4.6.** Coverage includes the new reuse cases, exact paid history/accounting preservation, original expiry, source/approval/quote/forecast invalidation, repeated reconciliation, NBA acquisition/context and NFL compatibility/write efficiency. The focused reuse suite passes 25 tests / 113 assertions.
- The existing cross-table numeric-ID collision test now explicitly constructs its collision. Its prior reliance on coincident auto-increment sequences failed under MySQL, whose counters survive test transaction rollback. No application behavior changed for this fixture correction.
- The additive migration installs both lineage foreign keys and the three-column unique identity. MySQL rejects orphan receipts. Rollback/reapply preserved the schemas and rows of **32 NFL tables and five shared forecast/review/accounting tables**, including synthetic NFL team and paid-usage fixtures. This is a disposable database, not a production clone. The test database was dropped, its socket-only server stopped and temporary data removed.
- Scoped Pint and whitespace checks pass. No API shape, permissions, frontend, prediction formula, public approval or scheduler change was introduced.

## Production readback

At **2026-11-21T18:54:39-06:00**, the existing authorized Laravel Cloud console read-only transaction verified:

- NBA game 1710 remains `STATUS_SCHEDULED`. Effective team admission remains **2 and 26** only, expiring at **2026-11-22T01:55:04+00:00**. The window was not extended.
- Source capture and structured research admission remain enabled within that existing gate. Analysis scheduler and publisher remain **false**. Production migration count remains **355**; the candidate migration has not been applied there.
- Structured source registrations remain two team injury sources plus the existing event-context source. Context storage remains two packets for game 1710 only; observation/state team IDs remain 2 and 26.

The existing expanded source packet still reports projected, unconfirmed starters and availability conflicts. This pass did not fetch a newer lineup packet or treat projected starters as confirmed. The prior verified packet is context snapshot 2 / research report 2608, assessed at 6:32 p.m. Central. See [the expanded research contract](nba-expanded-context-research.md) for interpretation limits.

## Next gate

Review [the candidate contract, activation and rollback steps](nba-review-provenance-reuse.md). Identical archived content can reuse an unexpired paid interpretation with appended provenance; broad claim-only deduplication remains deferred. A bounded production reuse canary and verified overnight/pregame acquisition are still required before automation. PDFs remain optional and NFL behavior remains on its existing path.

[Redacted verification details and file hashes](nba-review-reuse-verification-2026-11-21.json).
