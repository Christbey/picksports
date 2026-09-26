# API Authentication: V1 Retirement Complete

Updated 2026-09-25. The application owner confirmed there are no external V1
clients and authorized removal. The V1 auth routes, usage logger and report
commands have been removed.

Use `/api/v2/auth/login`, `/api/v2/auth/passkeys/options`,
`/api/v2/auth/passkeys/verify`, `/api/v2/auth/me`, `/api/v2/auth/logout`, and
`/api/v2/auth/logout-all`. Existing tokens remain valid; the shared token and
passkey services were retained. V2 also supports OAuth and native device
sessions. V1 paths return 404.

See [the retirement record](api-v2-contracts-and-retirement.md) for the complete
application migration and deployment considerations.
