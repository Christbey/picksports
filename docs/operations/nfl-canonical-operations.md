# NFL canonical operations

Scope: operating principles for canonical NFL forecast publication and evaluation. Reviewed November 17, 2026 from repository source references; no active production flags or deployment state were verified. Start with [production access](production-access.md).

## Inspect before changing anything

Confirm the selected calculation release, read/write flags, last successful pipeline heartbeat, worker backlog, published revision lineage, and target kickoff. Check command signatures with `--help` and inspect readiness for the requested season/week. A valid response must identify its forecast source; an imported legacy row alone is not proof of a verified native calculation.

Publication must retain immutable input, calculation, market and prediction identities. Keep stored source observation times distinct from query time. Do not run generation, evaluation, sync, or research retry merely to inspect a game. Any repair must preserve old records and have a bounded explicit scope.

## Recovery checks

A successful command or deployment is not sufficient: verify target-game completeness, safe published revision, correct release/source metadata, freshness, evaluation coverage, and retained research revision links. Confirm both queues have workers and distinguish dispatch from completed execution. Repeated forecast runs must not multiply independent game samples.

## Rollback

If generation or API smoke tests fail:

1. Keep or restore `PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false`; this returns
   reads to the legacy path without deleting audit data.
2. Set `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=false` only to stop new
   canonical writes. Expect the independent readiness sentinel to fail and
   alert until the incident is resolved.
3. Leave research enabled unless it is the failing subsystem. To stop new web
   research, set `NFL_RESEARCH_PIPELINE_ENABLED=false`; existing evidence and
   revisions remain immutable and readable.
4. Do not delete or mutate published predictions, feature snapshots, market
   snapshots, decisions, or settlements. Roll back application code and
   environment configuration instead.
5. Do not reverse additive lineage migrations as an incident shortcut; retain the
   audit data while restoring a compatible release/configuration.
