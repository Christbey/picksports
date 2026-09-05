# ADR 0003: Authentication boundaries for web, native, and developer clients

- Status: Accepted
- Date: 2026-08-12

## Context

Browser sessions, installed mobile applications, and third-party server integrations have different security properties. Reusing long-lived personal tokens or placing a client secret in a native application would weaken revocation and credential handling.

## Decision

The Inertia web application keeps Laravel's secure cookie and session authentication, including CSRF protection. First-party iOS and Android clients use OAuth 2 authorization code flow with PKCE through the same Identity module. Native clients are public clients and never embed a client secret.

Native access tokens are short-lived. Refresh tokens rotate on every use, reuse revokes the token family, and device sessions can be listed and revoked. Passkeys and two-factor authentication are supported at the user authorization boundary. APNs and FCM registrations belong to revocable device records rather than user profile columns.

Developer organizations use hashed server-side credentials, explicit scopes, products, and entitlement policies. Authentication establishes the principal; the shared entitlement service decides quota, product, sport, and premium-field access for every transport.

Authentication events, credential changes, refresh reuse, and administrative revocation are audited. Secrets and raw refresh tokens are never stored in plaintext.

## Consequences

- Browser performance and CSRF protections remain intact.
- Lost devices and compromised refresh tokens can be revoked without changing a user's password.
- Swift and Kotlin SDKs implement a standard protocol instead of custom token exchange.
- Laravel Passport provides authorization-code and refresh-token behavior. A separate `OAuthUser` projection prevents Passport and Sanctum token-trait contracts from colliding on the primary user model.
- Existing Sanctum aliases remain accepted during migration. OAuth callers require explicit `mobile:read` or `mobile:write` scopes.
- The device-session ledger owns device metadata and APNs/FCM registrations; it is not presented as the OAuth server.
- Native clients are public clients with exact redirect URIs, no embedded secret, and an S256 PKCE verifier/challenge.
