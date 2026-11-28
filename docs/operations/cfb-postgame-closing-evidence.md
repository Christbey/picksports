# CFB postgame closing evidence

Apply the additive2026_11_26_070000 migration before deploying code that reads/writes the new receipt table. Its rollback deliberately refuses to erase captured evidence; use a reviewed forward migration. Existing snapshots/hashes are not repaired or retroactively certified.

The default command is a read-only scope preflight. Replace12345 with the reviewed internal CFB game ID; repeated --game accepts at most100 unique existing IDs and100 total forecasts:

```sh
php artisan cfb:record-closing-market-evidence --game=12345
php artisan cfb:record-closing-market-evidence --game=12345 --apply
```

Dry run does not assess quote/capture availability. Apply requires all selected games final with both scores; it uses stored data only. Exit0 means all returned evidence available; exit2 reports unavailable/missing evidence or invalid scope, while1 reports a refused apply. Read the per-game reason codes and appended/reused counts. If source changes during a multi-game run, previously completed games remain recorded; retry the explicit bounded scope after review. Matching latest observations are idempotent; A→B→A appends a new receipt, preserving the chain. This command does not grade forecasts, change approvals, fetch providers, retrain or rewrite snapshots.

Only future captures with the recorder-owned canonical integrity marker can produce verified entry evidence. Old absent markers are legacy_unverified; do not add markers, recompute old hashes or overwrite entry data to force availability. Capture consistency does not establish every feature's historical provider availability. Quote timestamps retain labeled legacy storage semantics; receipt observed_at is UTC with microseconds.

The prediction API exposes cfb_signal_context.closing_market_evidence with phase=postgame, availability/reasons, receipt identity, source timestamps and typed output. Compatibility market_movement closing aliases have explicit closing_evidence_provenance. An API read never records/retries evidence or refreshes a provider. Changed current game/entry context withholds stale output until an authorized bounded observation is recorded.

See the [implementation/proof report](../contracts/cfb-postgame-closing-evidence.md). Actual-engine verification and deployment remain separate gates; retained legacy snapshots may already contain historical postgame enrichment and are not silently repaired.
