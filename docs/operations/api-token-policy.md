# Password and passkey API token policy

This policy concerns `/api/v2/auth/login` and `/api/v2/auth/passkeys/verify`. It is separate from browser cookie sessions, Passport OAuth and native device-session refresh rotation. Source checkpoint: November 26, 2026; no production token inventory, activation, rotation or revocation occurred.

## Staged issuance

`API_TOKEN_FINITE_ISSUANCE_ENABLED` defaults to false. While off, interactive login preserves legacy wildcard credentials without explicit expiry and returns `token_profile=legacy_interactive`, actual abilities and null expiry metadata. A requested `read_only` profile is refused while inactive. This preserves existing clients during fleet preparation; it leaves the old-credential policy gate open.

After verified activation, `interactive` grants exactly `mobile:read` and `mobile:write`; `read_only` grants exactly `mobile:read`. Unknown profiles fail validation before token creation. `API_TOKEN_TTL_MINUTES` defaults to 60 and must be a whole number from 5 through 1440. Malformed lifetime/activation configuration fails issuance rather than creating an unbounded token. Entitlement, account, onboarding, verification and admin authorization still apply after bearer authentication.

Both login responses retain `token_type`, `access_token`, `user`, and `Cache-Control: private, no-store`. Additive metadata includes `token_profile`, `abilities`, exact UTC `expires_at`, integer `expires_epoch` and remaining `expires_in` seconds. Clients must honor the actual expiry and handle 401 by reauthentication or the established native refresh flow. Do not automatically replay failed mutations. Read-only tokens cannot POST logout; an authorized account-management session or approved owner/admin revocation flow must revoke them.

## Exact expiry and legacy compatibility

The expand-only 080000 migration adds nullable policy version/profile/expiry epoch fields to `personal_access_tokens` with an expiry index. The provided down method refuses marker/index removal; use a compatible forward repair. Existing rows remain unmarked; no historical timestamp, token name, ability or hash is reinterpreted as provenance. New finite credentials are created and marked atomically in one resolved user/token connection transaction. They leave naive `expires_at` null: that column cannot represent both repeated-hour instants reliably.

The dedicated provider composes Sanctum's previous validity decision and any existing callback with the marked-token policy. It never restores an already invalid token. For marked credentials it checks the known version, exact profile/abilities, valid epoch and exclusive expiry boundary using an explicit UTC clock. Partially marked or unknown policies fail closed. Unmarked legacy/native rows retain their original policy; cookie transient tokens and OAuth retain their separate guards. There is no custom PAT model or global Sanctum expiration change.

Turning issuance off stops new finite issuance but **does not disable enforcement of already marked tokens**. Direct third-party SQL/date readers must use canonical metadata for marked rows; null `expires_at` does not mean they are permanent. Source-bound guard/HTTP tests cover exact expiry and both Chicago fold instants. The three MySQL session-zone cases passed in an isolated frozen variant, together with bounded pruning controls (31 tests / 124 assertions). Later native callback changes and current-source upgrade acceptance remain separate; see the [implementation checkpoint](../contracts/api-token-policy.md) for executed evidence and limits.

## Cleanup

Sanctum's naive-date prune command does not select null-expiry marked rows. Use this separate bounded command:

```bash
# Preview one batch; no credentials or token hashes are printed.
php artisan auth:prune-policy-tokens --hours=24 --limit=1000
# Apply the same bounded policy; repeat deliberately using the returned cursor if needed.
php artisan auth:prune-policy-tokens --hours=24 --limit=1000 --apply
```

`--after-id` resumes a stable primary-key scan. The command rechecks known marked policy, expiry and actual native device-session links under its transaction. It does not remove unmarked legacy/native credentials, unexpired tokens, Passport tokens or tokens linked to a native session. Bounds are 1–1000 per batch and 0–8760 retention hours; malformed bounds fail before querying. A single batch does not certify the whole backlog.

When finite issuance is active, the scheduler invokes the 24-hour-retention/1000-token batch hourly with single-server execution, overlap protection and the existing heartbeat hook. Authentication expiry does not depend on cleanup completing. While issuance is off, cleanup is manual for existing marked rows. Monitor backlog and scheduler liveness before claiming capacity acceptance.

## Deployment and rollback gates

1. Apply the additive schema before any issuer uses it. Preserve nullable legacy rows and the new fields/index on forward-compatible rollback.
2. Deploy and verify the policy callback on **every authenticating HTTP instance and long-lived runtime** while issuance remains off. Drain old runtimes and prove the staged login/native/OAuth/browser and actual-engine acceptance matrix.
3. Verify the client response contract, supported reauthentication/native exchange, bounded cleanup and operational monitoring. Record the exact candidate, environment and flag values before enabling issuance.
4. A rollback must retain the epoch guard/provider and schema. Never expose marked null-mirror credentials to an older Sanctum guard: that guard would see them as nonexpiring. An incompatible rollback requires a separate reviewed, bounded revocation of all affected marked credentials before old authentication is exposed. No such production action has been performed; otherwise that rollback is blocked.

Changing this flag does not migrate existing permanent wildcard credentials. Inventory only approved metadata and actual native-session FK links, never plaintext/hash material; identify active clients, verify replacements, then execute a separately reviewed bounded cutoff/revocation plan. No blind production-wide purge or assumed client ownership is authorized by an ordinary login request. `AUTH-TOKEN-POLICY-01` remains partially remediated until actual rollout and legacy migration acceptance are complete.
