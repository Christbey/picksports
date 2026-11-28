# Odds snapshots

Scope: stored market evidence and its provenance. Reviewed against `GameOddsSnapshotRecorder`, `MarketQuoteRecorder`, and the snapshot/quote models on November 17, 2026. This is a code reference, not a statement of production coverage or quote availability.

## Storage and ownership

Sport game records hold the latest known market in `odds_data`, with event identity and update timestamps. These mutable fields serve current consumers; they cannot establish what was known at an earlier prediction time.

`App\Models\GameOddsSnapshot` stores preserved observations in `game_odds_snapshots`. Identify a game using both `game_table` and `game_id`, since IDs overlap across sports. Each snapshot records sport, provider event ID, source, start/capture times, SHA-256 payload hash, odds payload, and market context. The first bookmaker fields on a snapshot are summary metadata; the payload can contain multiple bookmakers.

`App\Services\OddsApi\GameOddsSnapshotRecorder` owns capture and calls `MarketQuoteRecorder` to normalize individual quotes into `market_quotes`. The normalized layer preserves quote identity and snapshot linkage for downstream selection and decision tracking. A snapshot existing does not mean every payload outcome normalized successfully.

## Recording behavior

The recorder compares the normalized payload hash with the latest captured snapshot for the same game. Changed payloads produce new observations. An unchanged payload is usually skipped, with an explicit exception for NFL and CFB: successful repeated fetches can create a fresh observation once half the configured maximum quote age has elapsed. This prevents an unchanged but newly observed football market from remaining permanently stale. See the recorder and each sport's quote-age configuration for the exact threshold.

Capture timestamps are converted to the configured application timezone before storage. Preserve the distinction between capture time, provider observation time, kickoff, and the time a query was run.

## Using historical evidence

Use frozen prediction inputs, preserved snapshots, and normalized quotes available before the target cutoff when evaluating past forecasts. Current game odds do not substitute for historical market evidence. Preserve source and quote/snapshot IDs so selections can be traced. Inspect normalization and coverage diagnostics before treating absence as proof that no market existed.

Historical coverage begins where preserved observations or explicitly sourced backfills exist. A current quote or newly imported historical row does not prove that the application possessed it before kickoff. Never backdate newly collected evidence to improve apparent availability or overwrite immutable forecasts during a reconstruction.

For access procedures, see [production access](operations/production-access.md) and [database access](operations/database-access.md). Ordinary inspection does not require a provider refresh or odds backfill.
