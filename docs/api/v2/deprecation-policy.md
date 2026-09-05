# API v2 Versioning and Deprecation Policy

## Compatibility

The major version is encoded in the path (`/api/v2`). We treat the following as compatible changes within v2: adding optional fields, endpoints, enum values, headers, or optional request parameters; expanding documented limits; and correcting behavior that did not match the published contract.

Removing or renaming fields or endpoints, changing field types or meanings, making optional input required, narrowing accepted input, or changing authentication semantics is a breaking change.

Clients should ignore unknown response fields and enum values, use documented identifiers rather than display labels, and retain the response request ID for support.

## Notice and retirement

For a planned breaking change, PickSports targets at least 180 days between public deprecation notice and retirement. Notice is published in this changelog and the developer communication channel on file. Affected responses may also include `Deprecation`, `Sunset`, and successor `Link` headers.

A notice identifies the affected contract, replacement, first deprecated date, planned sunset date, and migration instructions. A version remains supported until its published sunset date.

Urgent security, abuse-prevention, provider-rights, or legal requirements may require a shorter window. When that occurs, PickSports will publish the reason, scope, mitigation, and revised timeline as soon as practical without disclosing sensitive security details.

This policy is an operating policy and is not a promise beyond an applicable written agreement.
