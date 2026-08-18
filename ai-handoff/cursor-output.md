# Cursor output — Store Cloud SSO continue

**Status:** done  
**Date:** 2026-08-18  
**Production authentication complete:** no (Store 2.1.11 and Cloud 0.17.3 are local)

## This pass

Cloud cannot mint a WordPress nonce, so `?rwcc_open_cloud=1` without `_wpnonce` is the SSO start. WooCommerce login posts strip query args, so a short-lived `rwcc_open_cloud` cookie preserves intent until the store issues a hashed login claim.

## Files changed and why

- `includes/cloud-commerce/class-rwcc-account.php` — logged-out capture + Woo/WP login redirect + nonce optional for Cloud-originated links
- `woocommerce-api-subscription-bridge.php`, `readme.txt`, `docs/cloud-isolation-and-rollback.md` — 2.1.11
- `docs/cloud-commerce-bridge.md` — document the bounce
- `tests/cloud-commerce.php` — source assertions for SSO continue

## What was not changed

- Licence generation, downloads, My Account licence UI
- Claim hashing / identity subject
- Production deploy (not authorised)

## Commands run and results

See the implementation turn: `php tests/run.php` and Decision Cloud `npm test`.

## Remaining

- Production install of 2.1.11 on reactwoo.com
- `REACTWOO_CLOUD_BRIDGE_ENABLED` on production
- Align `REACTWOO_HANDOFF_SECRET`
