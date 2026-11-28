# GTM Event Map

The application can push the following `dataLayer` events on eligible, nonsensitive pages. Existing sensitive-document and sensitive-location guards suppress telemetry on authentication, settings, and subscription pages; this guide does not enable tracking there.

## Core events

- `page_view`
    - Params: `page_path`, `page_title`, `page_location`, `page_type`, `is_logged_in`, `user_tier`
    - Fired on initial page load and each Inertia navigation.

- `login_start`
    - Params: `login_method` (`password` or `passkey`)
    - Fired when login is submitted/started.

- `login_complete`
    - Params: `login_method`, `page_path`
    - Fired after successful login navigation.

- `sign_up_start`
    - Params: `sign_up_method` (`email`)
    - Fired when registration is submitted.

- `sign_up_complete`
    - Params: `sign_up_method`, `page_path`
    - Fired after successful registration navigation.

- `begin_checkout`
    - Params: `event_id`, `plan_name`, `plan_id`, `billing_cycle`, `value`, `currency`
    - Checkout intent only, not evidence of payment. The existing call remains subject to sensitive-page suppression.

- `purchase` is not emitted by the current checkout navigation flow.
    - A pending, open, uncertain, or acknowledged checkout is not a confirmed purchase. Authentication or dashboard navigation supplies no payment receipt.
    - Older locally stored pending `purchase` intents are discarded without emission. No replacement confirmed-purchase producer is introduced.
    - The retained pending events are `login_complete` and `sign_up_complete`; their existing authentication, expiry, and privacy gates remain in effect.

- `view_item`
    - Params: `item_id`, `item_name`, `content_type`, `sport`, `league`, `home_team`, `away_team`
    - Fired on sport game detail pages.

## GTM setup

1. Create Data Layer Variables for each parameter you want to send.
2. Create Custom Event triggers:
    - `page_view`
    - `login_complete`
    - `sign_up_complete`
    - `begin_checkout`
    - `view_item`
3. GA4 tags:
    - Use GA4 Config/Google tag on all pages.
    - Create GA4 Event tags for `login`, `sign_up`, `view_item`, `begin_checkout`.
4. Meta tags:
    - `login_complete` -> optional custom event `LoginComplete`
    - `sign_up_complete` -> standard `CompleteRegistration`
    - `begin_checkout` -> standard `InitiateCheckout`
    - Do not infer or configure a `Purchase` conversion from checkout intent or navigation. A future confirmed-purchase producer needs its own verified receipt contract and privacy review; none is qualified here.

## Review correction (November 27, 2026)

The earlier source concern described an emission on a subscription operation page. Actual helper controls showed that the existing privacy guard correctly suppresses that page. The demonstrated false purchase occurred only on a later authenticated dashboard navigation, where a previously stored checkout intent was flushed without payment confirmation. This correction changes neither billing state nor account access and does not qualify analytics transport or a live GTM container.
