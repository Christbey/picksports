# Native device session lifecycle

Native access and refresh tokens are issued through the authenticated API v2 device-session route. Refresh rotates the stored token hash once; reuse revokes the family, access token and push registrations. Raw refresh tokens are returned only to the caller, not stored. Token-bearing issuance and refresh responses use `Cache-Control: private, no-store`.

The token lifecycle requires all participating user, access-token, device-session, refresh and push models to use the same default database connection. Differently connected users/models are refused before token writes. This is an explicit supported boundary, not a distributed transaction implementation.

Push registration reloads and locks the current device session rather than trusting the caller's model instance. Revoked/deleted/reparented sessions cannot register delivery. A provider/token identity already owned by another account is refused; an account may move its own registration between active sessions. Concurrent unique collisions fail without transferring ownership. Registrations store encrypted delivery tokens and omit those tokens/hashes from serialized model output.

Ownership refusal also applies to a retained registration on a previously revoked session belonging to another account. Automatic account switching on the same physical device is therefore a compatibility gate: define and test an authorized release/re-registration flow before claiming that workflow is supported. Do not manually transfer registrations or remove ownership checks to make a device appear registered.

Registering locks the target session, registration and prior owner session. Cross-session moves/revocations can form database lock cycles; bounded transaction retries preserve atomicity, but retry exhaustion remains a possible failed request. Snapshot tests are not proof that every simultaneous schedule succeeds. Provider delivery, APNs/FCM credentials/environment ownership, notification consent and actual staged device workflows remain separate release gates. This pass sends no notifications.

## Epoch policy and bootstrap activation

New native epoch issuance defaults OFF (`NATIVE_CREDENTIAL_EPOCH_ISSUANCE_ENABLED=false`). Install and verify080/120 schema plus native-aware authentication on every runtime before activation. Known native-session-v1 credentials always use/enforce UTC epochs, including rotation with issuance OFF; legacy rotations upgrade only when ON. Raw legacy expiry columns are NULL only for new epoch credentials. Old rows keep their original timestamp semantics, including their known repeated-hour limitations; this pass does not infer historical instants.

Access TTL must be a whole1–1440 minutes and refresh TTL a whole1–365 days. Invalid durations or unsupported scope profiles refuse before credential creation. New credentials use fixed server mobile scope profiles, exact UTC duration arithmetic and current policy/session checks. Refresh expiry is enforced at the exact epoch. Never prune refresh history merely because its legacy expires_at is NULL; reuse detection requires the retained chain.

An actual native-session-linked parent bearer cannot bootstrap another family. With epoch admission ON, bootstrap requires a browser session or valid server-issued interactive API login provenance; it never converts Passport OAuth into another refresh protocol. Unlinked legacy bearers remain an explicit compatibility gate only while admission is OFF. Names do not prove provenance. Login/browser bootstrap remains separate from rotating the existing native refresh family.

Disabling new issuance is not permission to roll back to an old authentication binary: old code would not understand epochs and could accept raw-NULL expiry as unlimited.120 rollback refuses marker/history removal. Use a compatible forward repair and separately reviewed credential invalidation/fleet plan. Current native MySQL/session-timezone/concurrency and real device delivery/PKCE workflow acceptance remain independent release gates; focused local tests are not production activation evidence.

For native password/passkey clients, activate verified finite interactive API login issuance before enabling native epoch bootstrap. If API login still emits unmarked legacy tokens while native admission is ON, bootstrap deliberately refuses them; a device name cannot supply missing provenance. Stateful browser bootstrap remains separate. Verify the client transition and both activation gates together instead of enabling one flag in isolation.
