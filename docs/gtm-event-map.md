# GTM Event Map

This app now pushes these `dataLayer` events:

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
  - Fired when a paid subscription checkout starts.

- `purchase`
  - Params: `event_id`, `plan_name`, `plan_id`, `billing_cycle`, `value`, `currency`, `page_path`
  - Fired when checkout returns to an authenticated dashboard/subscription page.

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
   - `purchase`
   - `view_item`
3. GA4 tags:
   - Use GA4 Config/Google tag on all pages.
   - Create GA4 Event tags for `login`, `sign_up`, `view_item`, `begin_checkout`, `purchase`.
4. Meta tags:
   - `login_complete` -> optional custom event `LoginComplete`
   - `sign_up_complete` -> standard `CompleteRegistration`
   - `begin_checkout` -> standard `InitiateCheckout`
   - `purchase` -> standard `Purchase` with `value` and `currency`
   - Pass `event_id` on both `InitiateCheckout` and `Purchase` for browser/CAPI deduping.
