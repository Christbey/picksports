# Browser web push

Scope: browser subscriptions and VAPID delivery through `WebPushService`. Native APNs/FCM device-session registration is a separate local-token API and does not use this transport. Reviewed November 25, 2026 against source; no actual push delivery or production setting change was performed.

## Configuration and access

`services.web_push` reads `WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY` and `WEB_PUSH_VAPID_SUBJECT`. Keep the private key out of logs, documentation, command arguments and source control. Browser configuration exposes only the public key and whether the current account has an active stored subscription.

Generate/install keys with `webpush:generate-keys --write --subject=mailto:support@example.com` in the intended application environment. This writes the configured environment file with private permissions and does not print the private key. Existing nonempty keys require explicit `--write --rotate`; rotation requires deploying/reloading configuration and coordinating browser re-subscription. Do not rotate keys for a connection check. Without `--write`, the command intentionally prints new key material; run it only in a private terminal with output capture disabled. Subjects must be bounded mailto or HTTPS values without control characters, quotes, backslashes or interpolation syntax. Failed writes return a failing exit status; no configuration cache or delivery check runs automatically. See the [local command verification](../contracts/vapid-key-command-integrity.md).

The `settings/web-push/config`, `settings/web-push/subscriptions` POST/DELETE and `settings/web-push/test` routes require an authenticated, onboarded, verified session. Web mutations retain session CSRF protection. The test endpoint performs a real delivery when configured; do not use it for automated production acceptance or send real users test messages without authorization.

## Endpoint and ownership contract

Registration accepts HTTPS browser-push endpoints only from these explicit provider patterns:

- FCM: `fcm.googleapis.com`.
- Firefox: `updates.push.services.mozilla.com`.
- Safari: `web.push.apple.com`.
- WNS/Edge: one DNS label followed by `.notify.windows.com`.
- Legacy Chrome: `android.googleapis.com` with a path starting `/gcm/send/`.

All explicit ports, including `:443`, are rejected, as are URL credentials, fragments, IP literals, control characters and unapproved hosts. Registration does not resolve DNS or contact the endpoint. Opaque path/query token bytes are preserved. These are source admission patterns with synthetic coverage, not a certification of every browser/provider version. Review current stored host patterns and staged browser behavior before deploying an allowlist change; do not widen it to arbitrary origins merely to make a subscription pass.

The current unique `endpoint` column holds 255 characters. Registration enforces that limit and returns validation error 422 instead of a database truncation/error. Longer legitimate provider endpoints require a separately reviewed storage/identity migration; do not truncate an endpoint, drop its uniqueness constraint or silently hash away its actual destination.

An endpoint alone cannot reassign another account's subscription. A browser switching accounts must present the exact stored `p256dh` key, `auth` secret and encoding. Both key comparisons use `hash_equals`, under a row lock. A mismatch returns 409 with guidance to reset browser push permissions and create a new subscription; the existing owner and secrets remain unchanged. Deletion is always scoped to the authenticated user. Treat the endpoint and auth secret as private subscription credentials.

## Send-time egress controls

Every send, including a pre-existing stored subscription, is re-admitted by the endpoint policy. All DNS A/AAAA answers must be globally routable unicast addresses. Private, loopback, link-local, reserved/documentation, multicast, mapped IPv6, NAT64 and IPv4 transition/tunnel addresses are refused. Mixed public/private answers fail closed. An empty or failed resolution fails closed.

The selected validated address is pinned with `CURLOPT_RESOLVE`; the original HTTPS hostname is retained for certificate/SNI verification. Only hostname casing is normalized at the transport boundary. The opaque token path/query is unchanged. cURL redirects and proxies are disabled, HTTPS is the only admitted protocol, peer/name verification is mandatory, connect/send timeouts are 10/20 seconds and response capture is limited to 64 KiB. Older libcurl builds lacking the string protocol options use the HTTPS-only bitmask equivalent. There is no fallback to an unpinned connection after failure.

Provider response bodies, opaque endpoints and raw cURL errors are not logged. Failure logs retain subscription ID, exception class or HTTP status. Failed/unsupported legacy subscriptions are not automatically deleted or relabeled delivered. HTTP 404/410 retains existing expiry behavior. Delivery receipt updates and crypto code remain local until the admitted transport executes.

## Verification and remaining work

Security tests replace both DNS resolution and the final cURL wire boundary, generate synthetic P-256 key material locally, and make no actual DNS/HTTP/provider calls. They cover destination rejection, mixed answers, IP pinning, TLS/proxy/redirect settings, body bounds, legacy-row refusal, encrypted positive delivery, account switching and safe errors. The browser composable shows safe 409/422 guidance without exposing internal 5xx diagnostics or claiming activation after a failed save.

A staging canary with synthetic accounts is still required for actual DNS/certificate/IPv6/provider interoperability and delivered browser notifications. DNS lookup timing is governed by the platform resolver; cURL timeouts begin after application resolution. Single-address pinning fails safely if that address is unavailable; it currently does not try additional public answers. Per-user subscription caps, test-send throttling, total delivery budgets and long-endpoint storage remain follow-up work. Network egress policy at the hosting layer remains defense in depth; this application check cannot infer every environment's routing topology.

## Registration persistence acknowledgement

Registration preserves the existing unique-endpoint reservation and matching-key account-transfer contract. Inside the existing final transaction, the service pins the locked subscription ID, stages the provided owner, endpoint, key, encoding, user-agent and expiry fields, requires the final model save to succeed, then reads the literal subscription table on the same connection and compares the persisted fields before returning success. NULL and an explicitly empty user-agent are distinct. A cancelled save, changed row identity/store, or rewritten desired persisted field fails validation and rolls back that final transaction.

The initial unique-endpoint reservation occurs before this transaction and may remain after a refused final update; this is not a claim that all registration steps are atomic. This check does not change endpoint admission, possession-based transfers, encoding, encryption, send, removal or expiry policy. Local route tests forbid transport, DNS and notifications; they do not certify provider interoperability, physical-PDO replacement, arbitrary observer side effects on other connections, or concurrent account-transfer schedules.

Local qualification for this persistence boundary retained the original 12 cases, expanded to 17 registration cases. The final portable compatibility cohort passed 74 tests / 383 assertions; the same 17 registration cases passed 143 assertions under disposable MySQL 8.4.6 at 128 MiB, with strict canonical discovery/execution accounting. This is an after-only native qualification of this source epoch, not a production, concurrent-transfer, browser-provider or whole-suite certificate. All before-failure logs and the earlier NULL/empty comparison epoch remain preserved.
