# Bounded ready-queue pruning

Pruning is limited to ready work: at most 1,000 entries, 256 KiB per payload and 4 MiB total. Snapshot and mutation validate count, bytes and key types. Removal matches exact payload bytes and multiplicity, preserving unrelated order and same-UUID replacements. Reserved and delayed work are untouched. Unique locks are not force-released.

An oversized Redis element is materialized before Lua measures it; these budgets are not a hard Redis memory or latency guarantee. Database classification and Redis mutation are not one distributed transaction. Retained unique locks may delay requeue until expiry. Unexpected Redis errors, non-expiring locks, client prefixes and cluster topology require operator review before retry.
