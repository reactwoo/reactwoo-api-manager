# Current task — Decision Cloud identity handoff (Store side)

Canonical cutover: Decision Cloud `docs/identity-production-cutover.md`.

## Status (2026-08-19)

2.1.11 is tagged on GitHub. Production SSH `origin` still denied. Identity UUID for WP user 13 exists; Cloud bridge is loaded. Confirm wp-admin Version **2.1.11** if Sign in does not bounce back to Cloud.

## Do not

- Store or copy passwords
- Authenticate Cloud from webhook data
- Re-hook WooCommerce licence lifecycle
- Use WordPress user id as Cloud `--subject`
- Push production SSH unless authorised
