# Current task — Decision Cloud identity handoff (Store side)

Planner spec (2026-08-18): ReactWoo.com is the identity source. Commerce Bridge issues signed activation and returning-login claims. Do not replace licensing, downloads, or My Account licence UI.

## This pass (2.1.11)

Cloud Sign in must continue through My Account without a dashboard nonce (Cloud cannot mint `_wpnonce`). Logged-out customers keep SSO intent across Woo login via a short-lived cookie, then receive a login claim.

## Ownership

This repository (`reactwoo-api-manager`) owns WooCommerce accounts, licences, purchases, downloads, My Account, and the Commerce Bridge.

Decision Cloud owns Cloud users, organisations, sessions, and tenant authorisation.

## Do not

- Store or copy passwords
- Authenticate Cloud from webhook data
- Re-hook WooCommerce licence lifecycle
- Move Decision Cloud functionality into this plugin
- Push production unless separately authorised
