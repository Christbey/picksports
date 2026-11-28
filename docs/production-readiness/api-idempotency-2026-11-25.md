# Durable API idempotency — November 25, 2026

Scope: FA-01 / PR-07. Changes are local candidate code, an additive migration and isolated tests. No production migration, write, provider request or deployment was performed. The shared sports-contract workflow was applied; sports forecasts, permissions, payload fields and scheduler settings are unchanged.

## Contract and implementation

The optional `Idempotency-Key` contract remains optional. Unkeyed requests retain their existing behavior and do not receive a durability guarantee. A keyed request must target one of the 14 reviewed local-database operations in `AtomicIdempotentRoutes::operations()`. Unclassified keyed routes or mismatched model/default/receipt connections fail before business execution with 503 `idempotency_operation_unsupported`. An automated route-inventory assertion requires exact agreement between routes using `v2.idempotent` and the classification.

The durable reservation is created before the business transaction and contains only hashed key/scope/fingerprint, identity and timing metadata. New reservations carry `execution_mode=atomic_local_v1`. A row lock then serializes same-key requests. The callback's local business writes and serialized successful response receipt commit in **one transaction on the same connection**, with no automatic transaction retries in the middleware. A concurrent request waits for the row lock and then replays the committed status/body. A dropped client response after commit can be replayed without executing the operation again.

A worker death or exception before commit rolls back both business writes and receipt completion, leaving only the safe reservation. The same fingerprint can resume that reservation because its prior business transaction did not commit without a matching receipt. Successful response construction/serialization and receipt persistence are inside the transaction. A receipt-save failure therefore rolls back the business write as well. Any returned HTTP error (4xx or 5xx), or an unsupported streaming response, causes rollback; error responses are not retained as successful receipts. Validation can run again on an explicit retry. No receipt is blindly deleted after an exception or returned error.

The fingerprint remains scoped to principal type/ID, named route, key hash, method, route parameter identity and canonicalized request payload. Object key order is normalized; list order is meaningful. Raw keys and request payloads are not stored. Responses may contain private account data and must retain normal database access/backup protections.

## Classified operations

| Named route | Reviewed local mutations | External effects |
| --- | --- | --- |
| `v2.user-bets.store` | Create owned `UserBet` | None |
| `v2.user-bets.update` | Update owned `UserBet` | None |
| `v2.user-bets.destroy` | Delete owned `UserBet` | None |
| `v2.cbb-brackets.store` | Create owned `CbbBracket` | None |
| `v2.cbb-brackets.current.upsert` | Create/update owned season bracket | None |
| `v2.cbb-brackets.update` | Update owned bracket | None |
| `v2.cbb-brackets.destroy` | Delete owned bracket | None |
| `v2.groups.store` | Create group and owner `group_users` membership | None |
| `v2.groups.update` | Update owned group | None |
| `v2.alert-preferences.store` | Create/update preference row | No synchronous message delivery |
| `v2.alert-preferences.update` | Update preference row | No synchronous message delivery |
| `v2.auth.device-sessions.push-registrations.store` | Upsert encrypted local delivery-token row | No APNs/FCM network request |
| `v2.auth.device-sessions.push-registrations.destroy` | Set local registration revocation timestamp | No APNs/FCM network request |
| `v2.auth.device-sessions.destroy` | Revoke session, refresh tokens and push registrations; delete personal access token | No provider request or delivery |

Reviewed controllers, services and affected models have no synchronous provider/payment/mail/notification/queue side effects on these paths. Group/bracket UUID-generating model hooks are local. Group membership uses the group's connection. Native revocation uses nested same-connection transactions, so outer rollback includes token revocation. This classification is a maintenance contract: any change adding a model connection, provider call, delivery, filesystem mutation, manual commit, or new observer/listener requires re-review. Database transactions cannot undo external effects. Payment, auth issuance/refresh, admin research retries, and other routes without `v2.idempotent` are outside this admission list; do not silently add them.

## Legacy outcomes, expiry and pruning

The additive nullable `execution_mode` column preserves the distinction from prior releases. Existing completed receipts remain replayable until their original TTL expires. Existing **incomplete legacy receipts** may already have committed business writes, so they return 409 `idempotency_outcome_unknown` and never execute automatically, even after TTL. They require read-only reconciliation followed by an explicit operator decision; do not delete them or manufacture completion timestamps to enable a retry.

A completed expired receipt can start a new logical operation under the same key, serializing reset and the new write under its row lock. Incomplete atomic reservations retain their fingerprint beyond TTL and can resume only that operation; their completion starts a fresh replay TTL. Incomplete rows are excluded from `model:prune` regardless of provenance. Monitor their count/age and reconcile abandoned reservations rather than interpreting expiry as proof that no write occurred. Completed expired receipts remain prunable. A rare concurrent completed-receipt prune can return 409 `idempotency_reservation_expired`; retrying the same key is safe because the old replay TTL has ended.

The previous immediate 409 `idempotency_request_in_progress` behavior is replaced by database-lock serialization. Normal database lock timeout/deadlock errors may fail a request; they do not authorize executing a callback outside the transaction. The client should make a bounded explicit retry with the same key, not generate a fresh key after an ambiguous failure.

## Release and rollback requirements

1. Review this migration and run it on a disposable MySQL database first. The new column is nullable and does not alter existing data or constraints.
2. Apply the additive schema before admitting the new middleware. A source checkout alone does not establish deployed schema readiness.
3. Quiesce idempotent writes and drain old HTTP workers during cutover. **Do not run old and new middleware concurrently against new atomic reservations**: the old implementation does not honor their locking/transaction contract and may delete receipts on failure. Restart/recycle all old application workers before reopening writes.
4. Verify the exact deployed middleware/policy/model/migration, all 14 route bindings and same-connection configuration. Run bounded synthetic checks against staging; retain candidate-bound test evidence.
5. Keep the additive column on rollback. Its `down()` method intentionally refuses to erase execution provenance; a regression verifies the column and existing completed receipt remain intact after a rollback attempt. Returning to the old middleware while atomic reservations are admitted is unsafe without quiescence and reconciliation. Prefer disabling writes/maintaining the new reader until outcomes are reconciled; do not drop receipt data, run a destructive down migration or change old incomplete rows to the new marker.

## Validation evidence

Focused SQLite-memory checks exercise injected downstream failure after a business write; injected receipt persistence failure; safe same-key resumption/replay; 4xx/5xx rollback; legacy incomplete expiry/pruning refusal; atomic pending fingerprint retention after expiry; completed-only pruning; malformed keys; object/list/route-resource fingerprints; route classification and unsupported-key refusal. Existing tests retain principal scoping, completed TTL reuse, stable binding, delete replay and rate limiting. Every classified controller is also called twice through the actual API with the same key, requiring an identical replay and no bus/mail/notification/provider dispatch.

The dedicated opt-in `tests/Integration/MySql/ApiIdempotencyConcurrencyTest.php` uses independent PHP/PDO processes and committed fixtures. It verifies same-key lock waiting followed by one business write/replay, and SIGKILL after business insert but before receipt completion followed by rollback, safe resumption and replay. Its worker applies `TestDatabaseSafety` before providers, forces `DB_URL=''`, uses the explicitly admitted disposable MySQL target, and prevents stray HTTP calls. Tests migrate only the guarded disposable schema. They are excluded from the ordinary SQLite suite and must run sequentially with other schema-resetting integration tests.

The expanded SQLite-memory controller/domain set passed **57 tests / 460 assertions** (`AtomicApiIdempotencyTest`, `ApiV2WriteSafetyTest`, `GroupApiTest`, `NativeDeviceSessionApiTest`, `AlertPreferenceApiTest`, `CbbBracketApiTest`). An earlier focused SQLite + migration-guard set passed **43 tests / 189 assertions**. Scoped Pint and whitespace checks passed. The coordinating agent reported a real disposable **MySQL 8.4.6 run: 53 tests / 300 assertions, 62.56 seconds**, including both idempotency concurrency/process-death tests, three calculation concurrency tests and API/lifecycle contracts. That process had loaded tests before the later all-14-controller cases were added; their separate MySQL rerun remains pending at this record update. These are disposable local integration results, not production verification. The first MySQL test discovery exposed a PHPUnit final-method naming collision in a private harness helper; the helper was renamed before rerun. No business behavior was changed to suppress that harness error.

## Remaining boundaries

This closes the local business-write/receipt split for admitted routes, subject to the final engine/integration results. It does not certify every mutation route, external side effect, observer added later, arbitrary manual commits, replica routing, cross-database transactions or unsupported database drivers. It does not make unkeyed requests exactly-once or extend replay beyond the documented completed TTL. Same-key concurrency is serialized; distinct keys for the same business action remain separate operations unless the domain adds its own invariant. Incomplete-reservation monitoring/reconciliation and the coordinated worker cutover remain mandatory operational work.


Independent cross-review found a separate MySQL REPEATABLE READ publication race: an ordinary lookup before the event lock could pin a snapshot that hid a newer publication or rescheduled kickoff. The backend owner changed those admission checks to current locking reads/current locked event data. New deterministic tests and a guarded worker live in `PublicationSnapshotConcurrencyTest.php` / `publication-snapshot-worker.php`; their parent-coordinated execution is recorded separately from API idempotency. This API implementation's first business-transaction receipt read is itself locking/current, so it does not inherit that ordinary-read-before-lock flaw.
