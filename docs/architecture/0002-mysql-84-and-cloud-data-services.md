# ADR 0002: MySQL 8.4 for the first Laravel Cloud migration

- Status: Accepted
- Date: 2026-08-12

## Context

The active database is large and contains high-growth play, provider payload, feature snapshot, and prediction metadata tables. Changing the database engine while also changing infrastructure would combine two difficult migrations and make rollback harder.

## Decision

The first Laravel Cloud migration will use managed MySQL 8.4. Valkey will provide cache, sessions, queues, rate counters, and distributed locks. Private S3-compatible Laravel Object Storage will hold raw provider files, immutable datasets, model artifacts, and oversized metadata documents.

Before import, the source and target databases must be identified with `db:fingerprint`. A migration rehearsal must run `db:schema-health` against the approved source fingerprint and include exact row-count comparison. The fingerprint records the database name, server and driver, migration set, table definitions, indexes, foreign keys, sizes, and row counts.

Large immutable data is referenced from MySQL by URI, content hash, provider, and manifest. Searchable summaries and transactional state stay in MySQL. Historical plays and training rows may be exported to partitioned Parquet only after consumers and restoration procedures are proven.

Schema evolution follows expand/backfill/compare/contract. Storage and index changes require measured query plans and rollback procedures. Because allocated Cloud database storage cannot be reduced, sizing includes migration headroom and backfill duplication.

## Consequences

- Database behavior remains stable during infrastructure migration.
- Fingerprints prevent importing the older `picksports` schema when the active generation is expected.
- Object storage reduces future transactional database growth without making mutable application state eventually consistent.
- A later database-engine decision requires its own ADR and migration evidence.
