# Current task — Decision Cloud identity handoff (Store side)

Planner spec (2026-08-18): ReactWoo.com is the identity source. Commerce Bridge issues signed activation and returning-login claims. Do not replace licensing, downloads, or My Account licence UI.

## Ownership

This repository (`reactwoo-api-manager`) owns WooCommerce accounts, licences, purchases, downloads, My Account, and the Commerce Bridge.

Decision Cloud owns Cloud users, organisations, sessions, and tenant authorisation.

## This pass

- Immutable identity subject `_rw_cloud_identity_subject`
- Issuer `https://reactwoo.com`
- Server-to-server `POST /api/v1/identity/claims` (hash only)
- Activation URL fragment `#claim=`
- My Account dashboard “Open Decision Cloud” for any signed-in ReactWoo account (2.1.10)
- Login claims do not send a membership role

## Do not

- Store or copy passwords
- Authenticate Cloud from webhook data
- Re-hook WooCommerce licence lifecycle
- Move Decision Cloud functionality into this plugin
- Push production unless separately authorised
