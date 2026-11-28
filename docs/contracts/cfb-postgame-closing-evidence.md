# Separate CFB postgame closing evidence

Postgame closing enrichment is append-only evidence separate from the captured prediction snapshot. It preserves frozen feature, market and metadata payloads and hashes. Latest semantic repeats are idempotent; a later return to an older value appends a new revision. Actual capture chronology and normalized game identity are required for qualified entry evidence; caller markers and backdated generated times cannot manufacture it.

Stored-only collection is bounded and defaults to dry run for explicit game IDs. Reads present available, unavailable or legacy-unverified provenance and do not record or retry evidence. Historical missing markers and previously enriched snapshots are not repaired by relabeling. Retain additive receipt history and qualify schema, readers and workers before release; no calibration claim follows from a closing receipt.
