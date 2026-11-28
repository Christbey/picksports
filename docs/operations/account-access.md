# Account access and tier lifecycle

Source contract reviewed November 25, 2026. This guide describes the readiness candidate, not a claim that production runs it. Use [production access](production-access.md) for host/deployment authorization and [database access](database-access.md) for connection procedures. No mail, billing, account reconciliation or production mutations are authorized merely by reading this runbook.

## Email verification rollout and recovery

`User` implements Laravel's `MustVerifyEmail` contract. Fortify already enables email verification; the contract makes its registration notification listener and explicitly `verified` middleware effective. New registrations receive the verification notification. Existing accounts with `email_verified_at = null` must complete verification when opening a protected web route. An authenticated HTML request is redirected to the verification notice; a JSON request to the same protected route is denied with 403.

Profile edit/update remain reachable to an authenticated, onboarded user before verification, allowing correction of an inaccessible or mistyped address. Changing the email clears verification and the profile shows the new verification state. Resending from the notice/profile uses the authenticated `verification.send` flow; clicking the signed link completes the existing verification flow. Logout and the existing login/password-recovery/onboarding paths remain available. Existing native API authentication/context/device issuance retain their current middleware; this release does not add a blanket API email-verification restriction or weaken token abilities, ownership or entitlement checks.

Before releasing the enforcement change:

1. Confirm the actual target's mail transport and sender are intentionally configured, using secret-safe configuration inspection. `config/mail.php` defaults to the **log** mailer; log/array transports do not establish real delivery. Verify the application's public HTTPS host and signed verification links resolve through the intended proxy/domain.
2. Use an isolated staging account and controlled mailbox to verify registration, receipt of the message, signature/expiry handling, resend, changed-email re-verification, and protected-page access after verification. Test the recovery profile before verification and check that native onboarding remains accessible as intended. These checks send real messages and change staging account state; use only an approved staging target.
3. Assess the count and support impact of existing unverified accounts through authorized read-only access. Communicate verification/recovery guidance before rollout. Do not bulk mark accounts verified, disable verification middleware or bulk send notifications as a deployment shortcut.

If a user cannot receive a link, let them correct their address through profile settings and request a new verification message; check transport failures, throttling, sender/spam handling and domain/signature configuration. Do not ask for their password, OTP, recovery code or signed URL in tickets/logs. Escalate lost-account ownership through the established support process. A successful notification fake proves dispatch behavior, not mailbox delivery. On a release failure, use the coordinated deployment recovery procedure; editing verification timestamps is not rollback.

## Tier identifiers, access and deletion

Tier slugs are stable access identifiers. Admin updates can change display name, pricing, availability and permissions but cannot rename an existing slug. Attempting a rename returns actionable 422 validation: create a distinct tier and plan the user/billing migration explicitly. New slugs use lowercase letters/digits with hyphen/underscore separators. Admin, super-admin, super_admin and the configured founding role are reserved; a new tier cannot adopt an already existing independent role and overwrite its permissions. A concurrent identifier collision is refused rather than silently adopting the winner's role.

Tier and role creation/permission synchronization share a local transaction. Tier updates and the separate permissions page atomically save their overrides and role permissions. The sync service locks/refetches persisted tiers; a stale deleted tier object cannot recreate an orphan access role. Subscription-driven role sync recognizes configured and database tier slugs, including inactive rows, while preserving independently assigned roles and the admin flag.

Deletion is refused when a tier has a role row, configured tier entry, default flag, configured `subscriptions.default_tier` identity, founding-tier identity or billing price reference. The response directs the operator to deactivate and plan an explicit migration. An unused, unconfigured tier without those references remains deletable. **Deactivation changes availability; it does not prove that existing paid subscriptions, role assignments or billing entitlements have been revoked.** Do not cancel external billing inside an unreviewed local database transaction.

There is no automatic cleanup of roles left by historical hard deletes/renames or manual changes. A role name alone does not prove historical ownership. Resolve those cases with an explicit provenance-led migration that preserves independent/admin roles, accounts for active billing and is separately reviewed before production writes. No bulk cleanup was performed in this readiness pass.

## Two-factor secret lifecycle

The settings page, setup modal and recovery panel share client state only to support the current setup flow. Each secret read has a distinct request owner; replacement reads abort prior requests. Clear/disposal aborts pending reads and erases QR, setup and recovery data. Completion checks request ownership after body parsing, so a delayed or cancellation-ignoring transport cannot restore an old account's secrets. Regenerating recovery codes must show the new codes rather than a delayed previous response.

The visually hidden recovery panel is also aria-hidden/inert and removes secret contents from markup until revealed; the toggle exposes expanded/control state. OTP, recovery and manual-key/copy controls are named. These are presentation safeguards: recovery codes and setup secrets must still be treated as credentials. Do not log, screenshot or paste real values into readiness artifacts. Store recovery codes in a secure password manager. Existing Fortify confirmation/password requirements remain authoritative.

## Evidence and remaining gates

The candidate's source reviews and exact hashes are in the account authorization review (private verification record retained separately) and account UI review (private verification record retained separately). Focused tests cover verification denial/admission, registration notification dispatch, local write rollback, role preservation and tier lifecycle refusal. Ten additional UI regressions exercise controlled delayed secret responses and actual compiled component templates/setup. The final local frontend suite passed **436 tests**, formatting/ESLint/Vue type checks and client/SSR builds.

The earlier 28 slate plus 10 settings browser cases predate these latest account UI changes and do **not** certify them. The compiled-component regressions use synthetic fixtures and mocked surrounding navigation/layout/provider integrations. Fresh supported-browser account workflows, keyboard/screen-reader checks, real staging mail, checkout/webhook timing and actual deployment/configuration acceptance remain release gates. Final integrated PHP/MySQL evidence is maintained by the coordinating readiness report.


## Group invitation acceptance — November 27 private candidate

Email invitation and join-link URLs remain GET entry points. For signed-in users, GET and HEAD show a private, escaped confirmation without changing membership; acceptance requires an authenticated POST to the same token URL with a valid CSRF token. The document retains no-referrer/no-store handling and loads no third-party scripts. Guest registration continues to submit its existing invite_token/join_token fields.

Both paths use locked current token, group and user rows on one connection. Pending/active status and the invited email are checked again before writing. Membership and the invitation acceptance receipt commit together; an exception or cancelled receipt save rolls back membership. Accepted invitations refuse further acceptance; an active join-link retry retains one membership.

If a token expires or is revoked during registration, an already created account may remain, but no membership is added. Do not alter token state or receipt fields merely to complete a failed join. Inspect the current token, account and membership and issue a new authorized invitation where appropriate.

Scoped SQLite HTTP/transaction verification passed 31 tests / 144 assertions, including enabled-CSRF refusal/positive, actual stale registration callbacks and both receipt failure channels, followed by independent source review. Later native service verification passed nine tests / 84 assertions for current-token locking, revocation ordering, repeat acceptance and receipt rollback. Native HTTP coverage for every route and manual PDO/privileged SQL replacement remain separate gates. This later repair is excluded from the second frozen full checkpoint.

## Checkout return reads — November 27 private candidate

`GET` and `HEAD /subscription/success` are authenticated navigation, not proof of payment. They redirect to subscription management with a neutral reconciliation message, without synchronizing roles or declaring a subscription active. This also keeps provenance-managed accounts out of the legacy role writer. The checkout POST still supplies the same success URL.

Legacy paid access is reconciled by the signature-verified subscription webhook, whose subscription and role writes share the existing transaction; durable billing retains its existing confirmed reconciliation path. A synthetic signed subscription-created HTTP regression demonstrates legacy paid-role assignment without visiting the return URL. No real payment, provider request, message or deployment was exercised. This later repair is outside the third frozen whole-PHP checkpoint.


## OAuth identity persistence — November 27 private candidate

A verified provider response alone does not complete local login. Identity lookup, any new-account provisioning and entitlement writes, and the OAuth account save now share an outer transaction. A cancelled save or missing/mismatched persisted identity header refuses login, redirects to the login page with a generic OAuth error, and rolls back local provisioning. Authentication/session regeneration follows successful completion. Existing provider identity ownership remains authoritative when its verified email changes. Provider state, verification and linking policies are preserved.

Two actual HTTP cancellation controls failed before the repair; the formatted candidate passed 14 tests / 107 assertions including existing OAuth/onboarding, token serialization and impersonation behavior. Socialite transport was mocked; no real provider was called. Default connection/read routing, inherited transactions, concurrent new-account uniqueness, native provider-ID collation, encryption at rest and production/browser acceptance remain separate gates. See the exact source/evidence receipt (private verification record retained separately).


## Passkey credential storage — November 27 private candidate

Registration now refuses a recovered credential owned by another account before updating any existing row. Cancelled persistence is a validation failure; current primary ownership must match before success. Challenge, origin, RP, user verification, signing and authentication policies remain unchanged.

The forward-only `2026_11_27_120000_expand_passkey_credential_identity` migration expands credential storage from 255 to 512 characters and uses `utf8mb4_bin` for case-sensitive canonical base64url IDs. Native controls demonstrated the prior case collision and MySQL length refusal. The migration checks actual metadata and a complete unique index before DDL, preserves other attributes/indexes/rows, and refuses unexpected schema drift. The binary collation is PAD SPACE; this is a canonical-base64url identity contract, not arbitrary byte equality. SQLite does not enforce VARCHAR length.

Native checks passed 4 tests / 44 assertions, with 50 / 112 separate architecture/authentication compatibility checks. These are disposable MySQL controls, not production migration, online DDL, hardware or fleet acceptance. Verify target metadata and release prerequisites before applying. `down()` deliberately refuses destructive shrink/case folding; recovery requires a reviewed forward repair. See the exact migration receipt (private verification record retained separately).


OAuth also refuses absent, empty, ASCII whitespace/control-containing, non-string/non-integer or over-255-byte provider subjects before identity lookup, retaining nonempty opaque `0`. Actual mocked-provider HTTP controls previously authenticated an unrelated retained malformed-subject owner despite a different verified email. The malformed rows remain unchanged; no external Google exploit or real-provider malformed response is claimed. The admitted subject is frozen through the transaction. Source and test qualification (private verification record retained separately) preserves the original combined and subsequent independently runnable test epochs.

## Subscription middleware tier names — November 27, 2026

The private candidate treats an explicit `subscribed:<tier>` requirement as an exact configured tier key. Unknown names, case differences, empty names and `0` refuse access. Omitted requirements retain the base active-subscription check; configured tier ordering and explicit user bypass retain their existing behavior. No production application route currently uses this alias in the inspected route inventory; the demonstrated failure used temporary registered web/API test routes.

Scoped repair evidence (private verification record retained separately): 21 tests/46 assertions including all nine existing middleware cases. This later private change requires the next combined run and has not been deployed.
