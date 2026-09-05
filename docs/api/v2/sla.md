# Public API v2 Service-Level Objectives

These are operational objectives for API v2, not a contractual service-level agreement unless incorporated into a separate written agreement.

## Availability objective

The production API target is 99.9% successful availability per UTC calendar month for covered API v2 requests. A covered request is successful when it does not return an unexpected PickSports `5xx` response. Client errors (`4xx`), documented rate limiting, sandbox traffic, scheduled maintenance announced at least 48 hours ahead, and failures of networks or systems outside PickSports control are excluded.

Availability is calculated as:

`successful covered requests / total covered requests × 100`

## Incident response objectives

| Severity | Example | Initial response target | Update target |
| --- | --- | --- | --- |
| SEV-1 | Production API broadly unavailable or materially returning invalid data | 30 minutes | Every 60 minutes |
| SEV-2 | Major endpoint or data family impaired with a workaround | 4 business hours | Each business day |
| SEV-3 | Limited defect or documentation issue | 2 business days | At material milestones |

Recovery takes priority over root-cause publication. For qualifying SEV-1 incidents, the target is to publish a summary within five business days after resolution.

## Reporting an incident

Provide the UTC timestamp, endpoint, HTTP status, environment, and `X-Request-ID`. Do not send API secrets, bearer tokens, or sensitive end-user data. The supported contact channel is the one listed in the developer account or applicable agreement.
