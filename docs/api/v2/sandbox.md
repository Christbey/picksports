# API v2 Sandbox Guide

The sandbox validates developer authentication, entitlement, quota, response-envelope, and observability behavior without promising production data freshness or completeness.

## Request

Ask an organization administrator for a sandbox developer credential and store it as a secret. Send it as a bearer token:

```bash
curl --request GET \
  --url https://picksports.app/api/v2/developer/sandbox \
  --header "Authorization: Bearer ${PICKSPORTS_SANDBOX_TOKEN}" \
  --header "Accept: application/json"
```

Never embed the credential in browser code, mobile application bundles, logs, screenshots, or source control. Rotate it after suspected exposure.

## What to validate

- Preserve `X-Request-ID` for diagnostics.
- Read quota limit, remaining, and reset headers; do not hard-code plan limits.
- Treat HTTP `429` as retryable only after the returned reset or retry guidance, with jitter.
- Handle the documented error envelope for authentication, entitlement, validation, quota, and server errors.
- Use an `Idempotency-Key` on supported write endpoints and reuse the same key only for the same logical request.

Sandbox quotas are isolated from production. Sandbox payloads may be synthetic, delayed, reset, or removed and must not be used for production decisions. A successful sandbox request confirms integration mechanics, not production access or provider redistribution approval.

## Moving to production

Production activation requires an active organization, product entitlement, production credential, an agreed quota, and completion of operational and provider-data readiness reviews. Credentials and data from one environment must not be assumed valid in another.
