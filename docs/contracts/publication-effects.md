# Publication effects and recovery

Publication effects require durable local intents and bounded recovery, separate from the transaction that records the published prediction. Apply the additive effects table before activating compatible publisher and worker code. Preserve receipt history; destructive rollback is refused.

Drain old publication workers during cutover because they cannot record durable intents. Do not automatically backfill old publications, reactivate canceled receipts or delete audit rows. Verify scheduler execution, queue liveness, bounded lag and exhausted-receipt ownership. Reverting to callback-only code reopens the delivery-loss boundary; use a reviewed forward repair or quiescence.
